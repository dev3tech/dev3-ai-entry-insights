import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { Button, Card, ErrorNotice, Inline, JobProgress, Modal, Notice, NumberField, PageHeader, ResponsiveTable, Select, Tabs, TextField, useToast } from '../core3/ui';
import EntryDrawer from './EntryDrawer';
import { CATEGORIES, SENTIMENT, SOURCES, ScorePill, SentimentBadge, Tags, URGENCY, UrgencyBadge, ago, options } from './helpers';
const PER_PAGE = 20;

export const VIEWS = [
	{ id: 'all', label: __( 'Inbox', 'dev3-ai-entry-insights' ) },
	{ id: 'new', label: __( 'New', 'dev3-ai-entry-insights' ) },
	{ id: 'hot', label: __( 'Hot leads', 'dev3-ai-entry-insights' ) },
	{ id: 'attention', label: __( 'Needs attention', 'dev3-ai-entry-insights' ) },
	{ id: 'starred', label: __( 'Starred', 'dev3-ai-entry-insights' ) },
	{ id: 'replied', label: __( 'Replied', 'dev3-ai-entry-insights' ) },
	{ id: 'archived', label: __( 'Archived', 'dev3-ai-entry-insights' ) },
];

const FILTER_KEYS = [ 'source', 'form', 'sentiment', 'category', 'urgency', 'tag', 'min_score' ];

/**
 * Entries inbox.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.navigate Router.
 * @param {number}   props.openId   Entry open in the drawer.
 * @param {Object}   props.query    Filters from the URL.
 * @param {Function} props.onUsage  AI used.
 * @param {Function} props.onCounts Counts changed.
 * @return {Element} Inbox.
 */
export default function Entries( { settings, navigate, openId, query = {}, onUsage, onCounts } ) {
	const toast = useToast();
	const [ view, setView ] = useState( query.view || 'all' );
	const [ filters, setFilters ] = useState( () => {
		const f = {};
		FILTER_KEYS.forEach( ( k ) => ( f[ k ] = query[ k ] || '' ) );
		return f;
	} );
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( query.search || '' );
	const [ q, setQ ] = useState( query.search || '' );
	const [ sort, setSort ] = useState( { key: 'created_at', dir: 'desc' } );
	const [ data, setData ] = useState( { items: [], total: 0, counts: {}, pending: 0 } );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ selected, setSelected ] = useState( [] );
	const [ action, setAction ] = useState( '' );
	const [ bulkTag, setBulkTag ] = useState( '' );
	const [ confirm, setConfirm ] = useState( false );
	const [ job, setJob ] = useState( null );
	const [ forms, setForms ] = useState( [] );
	const [ showFilters, setShowFilters ] = useState( FILTER_KEYS.some( ( k ) => query[ k ] && k !== 'form' ) );
	const timer = useRef( null );
	const queryKey = JSON.stringify( query );
	// Links like #/entries?tag=x (from the dashboard) set the filters; opening the drawer keeps them.
	useEffect( () => {
		if ( ! Object.keys( query ).length ) {
			return;
		}
		const f = {};
		FILTER_KEYS.forEach( ( k ) => ( f[ k ] = query[ k ] || '' ) );
		setFilters( f );
		setView( query.view || 'all' );
		setShowFilters( FILTER_KEYS.some( ( k ) => query[ k ] && k !== 'form' ) );
		setPage( 1 );
	}, [ queryKey ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const params = () => {
		const p = new URLSearchParams( { view, page: String( page ), per_page: String( PER_PAGE ), search: q, orderby: sort.key, order: sort.dir } );
		FILTER_KEYS.forEach( ( k ) => filters[ k ] !== '' && filters[ k ] !== null && p.set( k, filters[ k ] ) );
		return p;
	};

	const load = () => {
		setLoading( true );
		setError( null );
		request( `entries?${ params().toString() }` )
			.then( ( res ) => {
				setData( res );
				if ( onCounts ) {
					onCounts( res.counts );
				}
			} )
			.catch( setError )
			.finally( () => setLoading( false ) );
	};
	useEffect( load, [ view, page, q, sort.key, sort.dir, JSON.stringify( filters ) ] ); // eslint-disable-line react-hooks/exhaustive-deps
	useEffect( () => {
		request( 'forms' )
			.then( ( res ) => setForms( res.forms || [] ) )
			.catch( () => {} );
	}, [] );
	useEffect( () => {
		clearTimeout( timer.current );
		timer.current = setTimeout( () => {
			setPage( 1 );
			setQ( search.trim() );
		}, 300 );
		return () => clearTimeout( timer.current );
	}, [ search ] );
	// While entries wait for AI, refresh every 8 s so the insights fill in.
	useEffect( () => {
		if ( ! data.pending ) {
			return undefined;
		}
		const t = setTimeout( load, 8000 );
		return () => clearTimeout( t );
	}, [ data ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const setFilter = ( key ) => ( value ) => {
		setFilters( ( f ) => ( { ...f, [ key ]: value } ) );
		setPage( 1 );
	};
	const activeFilters = FILTER_KEYS.filter( ( k ) => filters[ k ] !== '' && filters[ k ] !== null ).length;
	const clearFilters = () => {
		const f = {};
		FILTER_KEYS.forEach( ( k ) => ( f[ k ] = '' ) );
		setFilters( f );
		setPage( 1 );
	};
	const changeView = ( v ) => {
		setView( v );
		setPage( 1 );
		setSelected( [] );
	};
	const toggle = ( id ) => setSelected( ( s ) => ( s.includes( id ) ? s.filter( ( x ) => x !== id ) : [ ...s, id ] ) );
	const allIds = data.items.map( ( e ) => e.id );
	const allOn = allIds.length > 0 && allIds.every( ( id ) => selected.includes( id ) );
	const aiReady = settings.api_key_set && settings.ai_enabled !== false && config.canGenerate;

	const runBulk = () => {
		setConfirm( false );
		request( 'entries/bulk', { method: 'POST', data: { ids: selected, action, tag: bulkTag } } )
			.then( ( res ) => {
				if ( res.job ) {
					setJob( res.job );
					toast.info( action === 'analyze' ? __( 'DEV3 AI is analysing the selected entries in the background.', 'dev3-ai-entry-insights' ) : __( 'Re-scoring the selected entries with your rules.', 'dev3-ai-entry-insights' ) );
				} else {
					/* translators: %d: entries */
					toast.success( sprintf( __( '%d entries updated.', 'dev3-ai-entry-insights' ), res.done ) );
				}
				setSelected( [] );
				setAction( '' );
				setBulkTag( '' );
				load();
			} )
			.catch( ( e ) => toast.error( e ) );
	};
	const star = ( entry ) =>
		request( `entries/${ entry.id }`, { method: 'POST', data: { starred: ! entry.starred } } )
			.then( load )
			.catch( ( e ) => toast.error( e ) );

	const exportUrl = () => {
		const p = params();
		[ 'page', 'per_page', 'orderby', 'order' ].forEach( ( k ) => p.delete( k ) );
		return `${ config.exportUrl }&${ p.toString() }`;
	};

	const actions = [
		{ value: 'read', label: __( 'Mark as read', 'dev3-ai-entry-insights' ) },
		{ value: 'unread', label: __( 'Mark as new', 'dev3-ai-entry-insights' ) },
		{ value: 'star', label: __( 'Star', 'dev3-ai-entry-insights' ) },
		{ value: 'unstar', label: __( 'Remove star', 'dev3-ai-entry-insights' ) },
		{ value: 'tag', label: __( 'Add tag…', 'dev3-ai-entry-insights' ) },
		{ value: 'untag', label: __( 'Remove tag…', 'dev3-ai-entry-insights' ) },
		{ value: 'archive', label: __( 'Archive', 'dev3-ai-entry-insights' ) },
		{ value: 'unarchive', label: __( 'Move back to inbox', 'dev3-ai-entry-insights' ) },
		{ value: 'analyze', label: __( 'Analyze with DEV3 AI', 'dev3-ai-entry-insights' ), disabled: ! aiReady },
		{ value: 'rescore', label: __( 'Re-score with rules (no AI)', 'dev3-ai-entry-insights' ) },
		{ value: 'delete', label: __( 'Delete', 'dev3-ai-entry-insights' ) },
	];
	const formOptions = [ { value: '', label: __( 'All forms', 'dev3-ai-entry-insights' ) } ].concat( forms.map( ( f ) => ( { value: f.key, label: f.title } ) ) );
	const hot = settings.hot_threshold || 70;

	const columns = [
		{
			key: 'select',
			label: __( 'Select', 'dev3-ai-entry-insights' ),
			width: '44px',
			render: ( e ) => (
				// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
				<span className="dev3ei-rowcheck" onClick={ ( ev ) => ev.stopPropagation() }>
					<input
						type="checkbox"
						checked={ selected.includes( e.id ) }
						onChange={ () => toggle( e.id ) }
						aria-label={ /* translators: %s: sender */ sprintf( __( 'Select entry from %s', 'dev3-ai-entry-insights' ), e.name || e.email || e.id ) }
					/>
				</span>
			),
		},
		{
			key: 'name',
			label: __( 'Entry', 'dev3-ai-entry-insights' ),
			primary: true,
			render: ( e ) => (
				<span className="dev3ei-ecell">
					<span className="dev3ei-ecell__who">
						{ e.status === 'new' && <span className="dev3ei-dot" aria-label={ __( 'New', 'dev3-ai-entry-insights' ) } /> }
						<span className={ e.status === 'new' ? 'dev3ei-strong' : '' }>{ e.name || e.email || __( 'Unknown sender', 'dev3-ai-entry-insights' ) }</span>
						{ e.starred && <span className="dev3ei-star" aria-label={ __( 'Starred', 'dev3-ai-entry-insights' ) }>★</span> }
					</span>
					<span className="dev3ei-clamp dev3ei-ecell__text">{ e.summary || e.excerpt || '—' }</span>
					<span className="dev3ei-muted">
						{ e.form_title } · { SOURCES[ e.source ] || e.source }
						{ e.ai_state === 'pending' ? ` · ${ __( 'DEV3 AI analysing…', 'dev3-ai-entry-insights' ) }` : '' }
					</span>
				</span>
			),
		},
		{
			key: 'lead_score',
			label: __( 'Score', 'dev3-ai-entry-insights' ),
			sortable: true,
			nowrap: true,
			render: ( e ) => <ScorePill score={ e.lead_score } hot={ hot } />,
		},
		{
			key: 'insight',
			label: __( 'Insight', 'dev3-ai-entry-insights' ),
			render: ( e ) => (
				<span className="dev3ei-chips">
					<SentimentBadge value={ e.sentiment } />
					{ e.urgency !== 'low' && <UrgencyBadge value={ e.urgency } /> }
					<span className="dev3ei-muted">{ CATEGORIES[ e.category ] || e.category }</span>
				</span>
			),
		},
		{ key: 'tags', label: __( 'Tags', 'dev3-ai-entry-insights' ), hideOnMobile: true, render: ( e ) => <Tags tags={ e.tags } max={ 3 } /> },
		{ key: 'created_at', label: __( 'Received', 'dev3-ai-entry-insights' ), sortable: true, align: 'end', nowrap: true, render: ( e ) => ago( e.created_at ) },
	];
	const tabs = VIEWS.map( ( v ) => ( { id: v.id, label: v.label, badge: data.counts[ v.id ] ? String( data.counts[ v.id ] ) : undefined } ) );
	const needsTag = action === 'tag' || action === 'untag';

	return (
		<>
			<PageHeader
				title={ __( 'Entries', 'dev3-ai-entry-insights' ) }
				description={ __( 'Every captured submission with its DEV3 AI insights. Click an entry to read, tag and reply.', 'dev3-ai-entry-insights' ) }
				actions={
					<Button variant="secondary" icon="file" href={ exportUrl() }>
						{ __( 'Export CSV', 'dev3-ai-entry-insights' ) }
					</Button>
				}
			/>
			<div className="dev3ei-tabs-scroll">
				<Tabs tabs={ tabs } value={ view } onChange={ changeView } variant="pills" label={ __( 'Inbox views', 'dev3-ai-entry-insights' ) } />
			</div>
			{ data.pending > 0 && (
				<Notice tone="ai">
					{ /* translators: %d: entries */ sprintf( __( '%d entries are waiting for DEV3 AI. Rule-based insights are shown until the analysis is done.', 'dev3-ai-entry-insights' ), data.pending ) }
				</Notice>
			) }
			{ job && (
				<JobProgress
					jobId={ job.id }
					job={ job }
					title={ __( 'Analysing entries', 'dev3-ai-entry-insights' ) }
					onDone={ () => {
						load();
						onUsage();
					} }
				/>
			) }
			<Card flush>
				<div className="dev3ei-toolbar">
					<TextField label={ __( 'Search entries', 'dev3-ai-entry-insights' ) } hideLabel type="search" value={ search } onChange={ setSearch } placeholder={ __( 'Search names, emails, answers, summaries…', 'dev3-ai-entry-insights' ) } />
					<Select label={ __( 'Form', 'dev3-ai-entry-insights' ) } hideLabel value={ filters.form } onChange={ setFilter( 'form' ) } options={ formOptions } />
					<Button variant={ activeFilters ? 'secondary' : 'ghost' } icon="sort" onClick={ () => setShowFilters( ! showFilters ) } aria-expanded={ showFilters }>
						{ activeFilters ? sprintf( /* translators: %d: active filters */ __( 'Filters (%d)', 'dev3-ai-entry-insights' ), activeFilters ) : __( 'Filters', 'dev3-ai-entry-insights' ) }
					</Button>
				</div>
				{ showFilters && (
					<div className="dev3ei-toolbar dev3ei-toolbar--filters">
						<Select label={ __( 'Source', 'dev3-ai-entry-insights' ) } value={ filters.source } onChange={ setFilter( 'source' ) } options={ options( SOURCES, __( 'All sources', 'dev3-ai-entry-insights' ) ) } />
						<Select label={ __( 'Sentiment', 'dev3-ai-entry-insights' ) } value={ filters.sentiment } onChange={ setFilter( 'sentiment' ) } options={ options( SENTIMENT, __( 'Any sentiment', 'dev3-ai-entry-insights' ) ) } />
						<Select label={ __( 'Category', 'dev3-ai-entry-insights' ) } value={ filters.category } onChange={ setFilter( 'category' ) } options={ options( CATEGORIES, __( 'Any category', 'dev3-ai-entry-insights' ) ) } />
						<Select label={ __( 'Urgency', 'dev3-ai-entry-insights' ) } value={ filters.urgency } onChange={ setFilter( 'urgency' ) } options={ options( URGENCY, __( 'Any urgency', 'dev3-ai-entry-insights' ) ) } />
						<TextField label={ __( 'Tag', 'dev3-ai-entry-insights' ) } value={ filters.tag } onChange={ setFilter( 'tag' ) } placeholder="hot-lead" />
						<NumberField label={ __( 'Min. score', 'dev3-ai-entry-insights' ) } value={ filters.min_score === '' ? '' : Number( filters.min_score ) } min={ 0 } max={ 100 } onChange={ ( v ) => setFilter( 'min_score' )( v === '' ? '' : String( v ) ) } />
						{ activeFilters > 0 && (
							<Button variant="link" onClick={ clearFilters }>
								{ __( 'Clear filters', 'dev3-ai-entry-insights' ) }
							</Button>
						) }
					</div>
				) }
				<div className="dev3ei-toolbar dev3ei-toolbar--bulk">
					<label className="dev3ei-selectall">
						<input type="checkbox" checked={ allOn } onChange={ () => setSelected( allOn ? [] : allIds ) } />
						<span>{ selected.length ? sprintf( /* translators: %d: selected */ __( '%d selected', 'dev3-ai-entry-insights' ), selected.length ) : __( 'Select all', 'dev3-ai-entry-insights' ) }</span>
					</label>
					<Inline gap={ 8 } className="dev3ei-toolbar__bulk">
						<Select label={ __( 'Bulk action', 'dev3-ai-entry-insights' ) } hideLabel value={ action } onChange={ setAction } placeholder={ __( 'Bulk actions', 'dev3-ai-entry-insights' ) } options={ actions } disabled={ ! selected.length } />
						{ needsTag && <TextField label={ __( 'Tag', 'dev3-ai-entry-insights' ) } hideLabel value={ bulkTag } onChange={ setBulkTag } placeholder={ __( 'tag-name', 'dev3-ai-entry-insights' ) } /> }
						<Button size="sm" variant="secondary" disabled={ ! selected.length || ! action || ( needsTag && ! bulkTag ) } onClick={ () => ( action === 'delete' ? setConfirm( true ) : runBulk() ) }>
							{ __( 'Apply', 'dev3-ai-entry-insights' ) }
						</Button>
					</Inline>
				</div>
				{ error && <ErrorNotice error={ error } onRetry={ load } /> }
				<ResponsiveTable
					caption={ __( 'Entries', 'dev3-ai-entry-insights' ) }
					columns={ columns }
					rows={ data.items }
					loading={ loading }
					sort={ sort }
					onSortChange={ ( s ) => {
						setSort( s );
						setPage( 1 );
					} }
					onRowClick={ ( e ) => navigate( `entries/${ e.id }` ) }
					rowActions={ ( e ) => (
						// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
						<span className="dev3ei-rowactions" onClick={ ( ev ) => ev.stopPropagation() }>
							<button type="button" className={ 'dev3ei-starbtn' + ( e.starred ? ' is-on' : '' ) } aria-pressed={ e.starred } aria-label={ __( 'Star', 'dev3-ai-entry-insights' ) } onClick={ () => star( e ) }>
								<span aria-hidden="true">{ e.starred ? '★' : '☆' }</span>
							</button>
						</span>
					) }
					pagination={ { page, perPage: PER_PAGE, total: data.total, onPageChange: setPage } }
					empty={ {
						icon: 'inbox',
						title: view === 'attention' ? __( 'All caught up', 'dev3-ai-entry-insights' ) : __( 'No entries here', 'dev3-ai-entry-insights' ),
						description: q || activeFilters ? __( 'Try another search or filter.', 'dev3-ai-entry-insights' ) : __( 'Submissions from your connected forms appear here as they arrive.', 'dev3-ai-entry-insights' ),
						action: q || activeFilters ? undefined : (
							<Button variant="secondary" href="#/forms">
								{ __( 'Connected forms', 'dev3-ai-entry-insights' ) }
							</Button>
						),
					} }
				/>
			</Card>
			<EntryDrawer id={ openId } settings={ settings } onClose={ () => navigate( 'entries' ) } onChanged={ load } onUsage={ onUsage } />
			<Modal
				open={ confirm }
				onClose={ () => setConfirm( false ) }
				size="sm"
				title={ __( 'Delete the selected entries?', 'dev3-ai-entry-insights' ) }
				description={ __( 'This removes them and their insights from DEV3 AI Entry Insights. Entries stored by Gravity Forms or WPForms Pro stay there.', 'dev3-ai-entry-insights' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setConfirm( false ) } data-autofocus>
							{ __( 'Cancel', 'dev3-ai-entry-insights' ) }
						</Button>
						<Button variant="danger" onClick={ runBulk }>
							{ __( 'Delete', 'dev3-ai-entry-insights' ) }
						</Button>
					</>
				}
			/>
		</>
	);
}
