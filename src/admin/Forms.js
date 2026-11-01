import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { AiButton, Badge, Button, Card, Columns, ErrorNotice, JobProgress, Modal, NumberField, PageHeader, ResponsiveTable, Toggle, Checkbox, useToast } from '../core3/ui';
import { SOURCES, ScorePill, ago } from './helpers';

/**
 * Forms: connected form plugins, per-form switches, import, bulk analysis.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.save     Save settings.
 * @param {Function} props.navigate Router.
 * @param {Function} props.onUsage  AI used.
 * @return {Element} Screen.
 */
export default function Forms( { settings, save, navigate, onUsage } ) {
	const toast = useToast();
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ job, setJob ] = useState( null );
	const [ importing, setImporting ] = useState( null );
	const [ limit, setLimit ] = useState( 200 );
	const [ importAi, setImportAi ] = useState( false );
	const [ busy, setBusy ] = useState( '' );
	const aiReady = settings.api_key_set && settings.ai_enabled !== false && config.canGenerate;

	const load = () => {
		setError( null );
		request( 'forms' ).then( setData ).catch( setError );
	};
	useEffect( load, [] );

	const toggleForm = ( form, key, value ) => {
		setData( ( d ) => ( { ...d, forms: d.forms.map( ( f ) => ( f.key === form.key ? { ...f, [ key ]: value } : f ) ) } ) );
		request( 'forms/config', { method: 'POST', data: { key: form.key, [ key ]: value } } ).catch( ( e ) => {
			toast.error( e );
			load();
		} );
	};
	const toggleSource = ( adapter, value ) =>
		save( { [ `source_${ adapter.id }` ]: value } )
			.then( () => {
				setData( ( d ) => ( { ...d, adapters: d.adapters.map( ( a ) => ( a.id === adapter.id ? { ...a, enabled: value } : a ) ) } ) );
				toast.success( value ? __( 'Capturing turned on.', 'dev3-ai-entry-insights' ) : __( 'Capturing turned off.', 'dev3-ai-entry-insights' ) );
			} )
			.catch( ( e ) => toast.error( e ) );
	const startImport = () => {
		setBusy( 'import' );
		request( 'forms/import', { method: 'POST', data: { key: importing.key, limit, analyze: importAi } } )
			.then( ( res ) => {
				setImporting( null );
				if ( res.job ) {
					setJob( { ...res.job, title: __( 'Importing entries', 'dev3-ai-entry-insights' ) } );
				} else {
					toast.info( res.message );
				}
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};
	const analyzeAll = ( ai ) => {
		setBusy( ai ? 'ai' : 'rules' );
		request( 'analyze-all', { method: 'POST', data: { scope: ai ? 'unanalyzed' : 'all', ai } } )
			.then( ( res ) => {
				if ( res.job ) {
					setJob( { ...res.job, title: ai ? __( 'Analysing with DEV3 AI', 'dev3-ai-entry-insights' ) : __( 'Re-scoring with rules', 'dev3-ai-entry-insights' ) } );
				} else {
					toast.info( res.message );
				}
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};

	const adapterById = {};
	( data ? data.adapters : [] ).forEach( ( a ) => ( adapterById[ a.id ] = a ) );

	const columns = [
		{
			key: 'title',
			label: __( 'Form', 'dev3-ai-entry-insights' ),
			primary: true,
			sortable: true,
			render: ( f ) => (
				<span className="dev3ei-ecell">
					<span className="dev3ei-strong">{ f.title }</span>
					<span className="dev3ei-muted">
						{ SOURCES[ f.source ] || f.source } · #{ f.id }
						{ ! f.live ? ` · ${ __( 'form removed or plugin inactive', 'dev3-ai-entry-insights' ) }` : '' }
					</span>
				</span>
			),
		},
		{
			key: 'total',
			label: __( 'Entries', 'dev3-ai-entry-insights' ),
			sortable: true,
			nowrap: true,
			render: ( f ) =>
				f.total ? (
					<Button variant="link" onClick={ () => navigate( `entries?form=${ encodeURIComponent( f.key ) }` ) }>
						{ String( f.total ) }
						{ f.unread ? ` (${ f.unread } ${ __( 'new', 'dev3-ai-entry-insights' ) })` : '' }
					</Button>
				) : (
					'0'
				),
		},
		{ key: 'avg_score', label: __( 'Avg. score', 'dev3-ai-entry-insights' ), sortable: true, nowrap: true, render: ( f ) => ( f.total ? <ScorePill score={ f.avg_score } hot={ settings.hot_threshold } /> : '–' ) },
		{ key: 'last_at', label: __( 'Last entry', 'dev3-ai-entry-insights' ), hideOnMobile: true, nowrap: true, render: ( f ) => ( f.last_at ? ago( f.last_at ) : '–' ) },
		{
			key: 'capture',
			label: __( 'Capture', 'dev3-ai-entry-insights' ),
			render: ( f ) => (
				// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
				<span onClick={ ( e ) => e.stopPropagation() }>
					<Toggle className="dev3ei-toggle--bare" label={ /* translators: %s: form */ sprintf( __( 'Capture entries of %s', 'dev3-ai-entry-insights' ), f.title ) } checked={ f.capture } onChange={ ( v ) => toggleForm( f, 'capture', v ) } />
				</span>
			),
		},
		{
			key: 'analyze',
			label: __( 'DEV3 AI', 'dev3-ai-entry-insights' ),
			render: ( f ) => (
				// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
				<span onClick={ ( e ) => e.stopPropagation() }>
					<Toggle className="dev3ei-toggle--bare" label={ /* translators: %s: form */ sprintf( __( 'Analyze %s with DEV3 AI', 'dev3-ai-entry-insights' ), f.title ) } checked={ f.analyze } onChange={ ( v ) => toggleForm( f, 'analyze', v ) } />
				</span>
			),
		},
	];

	return (
		<>
			<PageHeader
				title={ __( 'Forms', 'dev3-ai-entry-insights' ) }
				description={ __( 'Connected form plugins and what is captured from each form.', 'dev3-ai-entry-insights' ) }
				actions={
					<>
						<Button variant="secondary" icon="refresh" loading={ busy === 'rules' } onClick={ () => analyzeAll( false ) }>
							{ __( 'Re-score all with rules', 'dev3-ai-entry-insights' ) }
						</Button>
						<AiButton loading={ busy === 'ai' } disabled={ ! aiReady } onClick={ () => analyzeAll( true ) }>
							{ __( 'Analyze unanalysed with DEV3 AI', 'dev3-ai-entry-insights' ) }
						</AiButton>
					</>
				}
			/>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ job && (
				<JobProgress
					jobId={ job.id }
					job={ job }
					title={ job.title }
					onDone={ () => {
						load();
						onUsage();
					} }
				/>
			) }
			<Columns min={ 240 }>
				{ ( data ? data.adapters : [] ).map( ( a ) => (
					<Card key={ a.id } className="dev3ei-adapter">
						<div className="dev3ei-adapter__head">
							<h3>{ a.label }</h3>
							{ a.active ? <Badge tone="success" dot>{ a.version || __( 'Active', 'dev3-ai-entry-insights' ) }</Badge> : <Badge tone="neutral">{ __( 'Not active', 'dev3-ai-entry-insights' ) }</Badge> }
						</div>
						{ a.active ? (
							<>
								<Toggle label={ __( 'Capture new submissions', 'dev3-ai-entry-insights' ) } checked={ a.enabled } onChange={ ( v ) => toggleSource( a, v ) } />
								<p className="dev3ei-muted">{ a.can_import ? __( 'Existing entries can be imported per form.', 'dev3-ai-entry-insights' ) : a.import_note }</p>
							</>
						) : (
							<p className="dev3ei-muted">
								{ a.id === 'gravityforms' ? __( 'Install and activate Gravity Forms to capture its entries.', 'dev3-ai-entry-insights' ) : __( 'Install and activate the free plugin to capture its submissions.', 'dev3-ai-entry-insights' ) }{ ' ' }
								{ a.plugin_url && (
									<a className="dev3ei-link" href={ a.plugin_url } target="_blank" rel="noreferrer">
										{ __( 'Get it', 'dev3-ai-entry-insights' ) } ↗
									</a>
								) }
							</p>
						) }
					</Card>
				) ) }
			</Columns>
			<Card title={ __( 'Forms', 'dev3-ai-entry-insights' ) } flush>
				<ResponsiveTable
					caption={ __( 'Forms', 'dev3-ai-entry-insights' ) }
					columns={ columns }
					rows={ data ? data.forms : [] }
					rowKey="key"
					loading={ ! data && ! error }
					defaultSort={ { key: 'total', dir: 'desc' } }
					rowActions={ ( f ) => (
						// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
						<span className="dev3ei-rowactions" onClick={ ( e ) => e.stopPropagation() }>
							{ adapterById[ f.source ] && adapterById[ f.source ].can_import && f.live && (
								<Button size="sm" variant="secondary" onClick={ () => setImporting( f ) }>
									{ __( 'Import', 'dev3-ai-entry-insights' ) }
								</Button>
							) }
							{ f.edit_url && (
								<Button size="sm" variant="ghost" href={ f.edit_url }>
									{ __( 'Edit form', 'dev3-ai-entry-insights' ) }
								</Button>
							) }
						</span>
					) }
					empty={ {
						icon: 'file',
						title: __( 'No forms found', 'dev3-ai-entry-insights' ),
						description: __( 'Create a form in Gravity Forms, WPForms or Contact Form 7 and it shows up here.', 'dev3-ai-entry-insights' ),
					} }
				/>
			</Card>
			<Modal
				open={ !! importing }
				onClose={ () => setImporting( null ) }
				size="sm"
				title={ __( 'Import existing entries', 'dev3-ai-entry-insights' ) }
				description={ importing ? /* translators: %s: form */ sprintf( __( 'Copies the newest entries of "%s" into DEV3 AI Entry Insights. Entries imported before are skipped.', 'dev3-ai-entry-insights' ), importing.title ) : '' }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setImporting( null ) }>
							{ __( 'Cancel', 'dev3-ai-entry-insights' ) }
						</Button>
						<Button variant="primary" loading={ busy === 'import' } onClick={ startImport }>
							{ __( 'Start import', 'dev3-ai-entry-insights' ) }
						</Button>
					</>
				}
			>
				<NumberField label={ __( 'How many (newest first)', 'dev3-ai-entry-insights' ) } value={ limit } min={ 1 } max={ 2000 } onChange={ ( v ) => setLimit( v === '' ? 200 : v ) } />
				<Checkbox label={ __( 'Analyze them with DEV3 AI too', 'dev3-ai-entry-insights' ) } checked={ importAi } disabled={ ! aiReady } onChange={ setImportAi } help={ aiReady ? __( 'Uses AI tokens for each imported entry.', 'dev3-ai-entry-insights' ) : __( 'Add an API key to analyze imported entries.', 'dev3-ai-entry-insights' ) } />
			</Modal>
		</>
	);
}
