import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { AiButton, AiDraftReview, Badge, Button, Drawer, ErrorNotice, Modal, Notice, Select, Skeleton, TextField, Textarea, useToast } from '../core3/ui';
import { CATEGORIES, HISTORY, SOURCES, STATUS, ScorePill, SentimentBadge, Tags, UrgencyBadge, ago, byLabel, toDate } from './helpers';

const TONES = [
	{ value: 'friendly', label: __( 'Friendly', 'dev3-ai-entry-insights' ) },
	{ value: 'professional', label: __( 'Professional', 'dev3-ai-entry-insights' ) },
	{ value: 'concise', label: __( 'Concise', 'dev3-ai-entry-insights' ) },
	{ value: 'apologetic', label: __( 'Apologetic', 'dev3-ai-entry-insights' ) },
	{ value: 'enthusiastic', label: __( 'Enthusiastic', 'dev3-ai-entry-insights' ) },
];

const REPLY_FIELDS = [
	{ key: 'subject', label: __( 'Subject', 'dev3-ai-entry-insights' ), maxLength: 150 },
	{ key: 'body', label: __( 'Message', 'dev3-ai-entry-insights' ), multiline: true, maxLength: 3000 },
];

/**
 * Score gauge.
 *
 * @param {Object} props       Props.
 * @param {number} props.score Score.
 * @param {number} props.hot   Hot threshold.
 * @return {Element} Gauge.
 */
function Gauge( { score, hot } ) {
	const tone = score >= hot ? 'success' : score >= 40 ? 'warning' : 'neutral';
	return (
		<div className={ `dev3ei-gauge dev3ei-gauge--${ tone }` }>
			<div className="dev3ei-gauge__num">
				<strong>{ score }</strong>
				<span>/ 100</span>
			</div>
			<div className="dev3ei-gauge__track" role="meter" aria-valuemin={ 0 } aria-valuemax={ 100 } aria-valuenow={ score } aria-label={ __( 'Lead score', 'dev3-ai-entry-insights' ) }>
				<span className="dev3ei-gauge__fill" style={ { width: `${ score }%` } } />
				<span className="dev3ei-gauge__mark" style={ { left: `${ hot }%` } } title={ __( 'Hot lead threshold', 'dev3-ai-entry-insights' ) } />
			</div>
		</div>
	);
}

/**
 * Entry drawer: answers, insights, tags, reply, note, history.
 *
 * @param {Object}   props           Props.
 * @param {number}   props.id        Entry id (0 = closed).
 * @param {Object}   props.settings  Settings.
 * @param {Function} props.onClose   Close.
 * @param {Function} props.onChanged Entry changed.
 * @param {Function} props.onUsage   AI used.
 * @return {Element} Drawer.
 */
export default function EntryDrawer( { id, settings, onClose, onChanged, onUsage } ) {
	const toast = useToast();
	const [ entry, setEntry ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ aiError, setAiError ] = useState( null );
	const [ tagInput, setTagInput ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ tone, setTone ] = useState( settings.voice_tone || 'friendly' );
	const [ notes, setNotes ] = useState( '' );
	const [ draft, setDraft ] = useState( null );
	const [ compose, setCompose ] = useState( null );
	const [ template, setTemplate ] = useState( '' );
	const [ confirmDelete, setConfirmDelete ] = useState( false );
	const hot = settings.hot_threshold || 70;
	const aiReady = settings.api_key_set && settings.ai_enabled !== false && config.canGenerate;

	const apply = ( next ) => {
		setEntry( next );
		setNote( next.note || '' );
		if ( onChanged ) {
			onChanged();
		}
	};
	useEffect( () => {
		setEntry( null );
		setError( null );
		setAiError( null );
		setDraft( null );
		setCompose( null );
		setTemplate( '' );
		setNotes( '' );
		if ( ! id ) {
			return;
		}
		request( `entries/${ id }` )
			.then( apply )
			.catch( setError );
	}, [ id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const update = ( data, message ) => {
		setBusy( 'update' );
		return request( `entries/${ id }`, { method: 'POST', data } )
			.then( ( next ) => {
				apply( next );
				if ( message ) {
					toast.success( message );
				}
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};
	const analyze = () => {
		setBusy( 'analyze' );
		setAiError( null );
		request( `entries/${ id }/analyze`, { method: 'POST' } )
			.then( ( next ) => {
				apply( next );
				toast.success( __( 'Insights updated by DEV3 AI.', 'dev3-ai-entry-insights' ) );
			} )
			.catch( setAiError )
			.finally( () => {
				setBusy( '' );
				onUsage();
			} );
	};
	const acceptTags = ( tags, reject = false ) =>
		request( `entries/${ id }/tags/accept`, { method: 'POST', data: { tags, reject } } )
			.then( apply )
			.catch( ( e ) => toast.error( e ) );
	const addTag = ( e ) => {
		e.preventDefault();
		const tag = tagInput
			.trim()
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-|-$/g, '' );
		if ( ! tag ) {
			return;
		}
		setTagInput( '' );
		update( { tags: [ ...entry.tags, tag ] } );
	};
	const draftReply = () => {
		setBusy( 'draft' );
		setAiError( null );
		request( `entries/${ id }/reply-draft`, { method: 'POST', data: { tone, notes } } )
			.then( ( res ) => {
				setDraft( res );
				setCompose( null );
			} )
			.catch( setAiError )
			.finally( () => {
				setBusy( '' );
				onUsage();
			} );
	};
	const useTemplate = () =>
		request( `entries/${ id }/template`, { method: 'POST', data: { template } } )
			.then( ( res ) => {
				setCompose( { subject: res.subject, body: res.body } );
				setDraft( null );
			} )
			.catch( ( e ) => toast.error( e ) );
	const send = ( value ) => {
		setBusy( 'send' );
		return request( `entries/${ id }/reply`, { method: 'POST', data: value } )
			.then( ( next ) => {
				apply( next );
				setDraft( null );
				setCompose( null );
				/* translators: %s: email */
				toast.success( sprintf( __( 'Reply sent to %s.', 'dev3-ai-entry-insights' ), next.email ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};
	const gfNote = () =>
		request( `entries/${ id }/gf-note`, { method: 'POST' } )
			.then( () => toast.success( __( 'Added to the Gravity Forms entry notes.', 'dev3-ai-entry-insights' ) ) )
			.catch( ( e ) => toast.error( e ) );
	const remove = () =>
		request( `entries/${ id }`, { method: 'DELETE' } )
			.then( () => {
				setConfirmDelete( false );
				toast.success( __( 'Entry deleted.', 'dev3-ai-entry-insights' ) );
				if ( onChanged ) {
					onChanged();
				}
				onClose();
			} )
			.catch( ( e ) => toast.error( e ) );

	const title = entry ? entry.name || entry.email || __( 'Entry', 'dev3-ai-entry-insights' ) : __( 'Entry', 'dev3-ai-entry-insights' );
	const templates = ( settings.templates || [] ).map( ( t ) => ( { value: t.id, label: t.name } ) );

	return (
		<Drawer
			open={ !! id }
			onClose={ onClose }
			size="xl"
			title={ title }
			description={ entry ? `${ entry.form_title } · ${ SOURCES[ entry.source ] || entry.source } · ${ toDate( entry.created_at ).toLocaleString() }` : '' }
			footer={
				entry ? (
					<div className="dev3ei-drawer-actions">
						{ entry.status !== 'archived' ? (
							<Button variant="secondary" icon="box" loading={ busy === 'update' } onClick={ () => update( { status: 'archived' }, __( 'Archived.', 'dev3-ai-entry-insights' ) ) }>
								{ __( 'Archive', 'dev3-ai-entry-insights' ) }
							</Button>
						) : (
							<Button variant="secondary" icon="inbox" onClick={ () => update( { status: 'read' }, __( 'Moved back to the inbox.', 'dev3-ai-entry-insights' ) ) }>
								{ __( 'Move to inbox', 'dev3-ai-entry-insights' ) }
							</Button>
						) }
						<Button variant="ghost" onClick={ () => update( { starred: ! entry.starred } ) } aria-pressed={ entry.starred }>
							{ entry.starred ? `★ ${ __( 'Starred', 'dev3-ai-entry-insights' ) }` : `☆ ${ __( 'Star', 'dev3-ai-entry-insights' ) }` }
						</Button>
						<Button variant="ghost" onClick={ () => update( { status: 'new' } ) } disabled={ entry.status === 'new' }>
							{ __( 'Mark as new', 'dev3-ai-entry-insights' ) }
						</Button>
						<Button variant="danger" icon="trash" onClick={ () => setConfirmDelete( true ) }>
							{ __( 'Delete', 'dev3-ai-entry-insights' ) }
						</Button>
					</div>
				) : null
			}
		>
			{ error && <ErrorNotice error={ error } /> }
			{ ! entry && ! error && <Skeleton lines={ 8 } /> }
			{ entry && (
				<div className="dev3ei-drawer">
					<div className="dev3ei-chips">
						<Badge tone={ ( STATUS[ entry.status ] || {} ).tone }>{ ( STATUS[ entry.status ] || {} ).label || entry.status }</Badge>
						{ entry.email && (
							<a className="dev3ei-link" href={ `mailto:${ entry.email }` }>
								{ entry.email }
							</a>
						) }
						{ entry.entry_url && (
							<a className="dev3ei-link" href={ entry.entry_url } target="_blank" rel="noreferrer">
								{ /* translators: %s: form plugin */ sprintf( __( 'Open in %s', 'dev3-ai-entry-insights' ), SOURCES[ entry.source ] || entry.source ) } ↗
							</a>
						) }
					</div>

					<section className="dev3ei-insight" aria-label={ __( 'Insights', 'dev3-ai-entry-insights' ) }>
						<div className="dev3ei-insight__head">
							<h3>{ __( 'Insights', 'dev3-ai-entry-insights' ) }</h3>
							<span className="dev3ei-muted">
								{ entry.ai_state === 'pending' && __( 'DEV3 AI analysis queued…', 'dev3-ai-entry-insights' ) }
								{ entry.ai_state !== 'pending' && entry.insight_src === 'ai' && sprintf( /* translators: %s: model */ __( 'By DEV3 AI · %s', 'dev3-ai-entry-insights' ), entry.model ) }
								{ entry.ai_state !== 'pending' && entry.insight_src !== 'ai' && __( 'Rule-based (no AI)', 'dev3-ai-entry-insights' ) }
							</span>
						</div>
						<Gauge score={ entry.lead_score } hot={ hot } />
						{ entry.lead_reason && <p className="dev3ei-muted">{ entry.lead_reason }</p> }
						{ entry.summary && <p className="dev3ei-summary">{ entry.summary }</p> }
						{ entry.intent && (
							<p className="dev3ei-intent">
								<span className="dev3ei-muted">{ __( 'Intent', 'dev3-ai-entry-insights' ) }:</span> { entry.intent }
							</p>
						) }
						<div className="dev3ei-chips">
							<SentimentBadge value={ entry.sentiment } />
							<UrgencyBadge value={ entry.urgency } />
							<Badge tone="info">{ CATEGORIES[ entry.category ] || entry.category }</Badge>
							{ entry.language && <Badge tone="neutral">{ entry.language.toUpperCase() }</Badge> }
							{ entry.insight_src === 'ai' && entry.rule_score !== entry.lead_score && (
								<span className="dev3ei-muted">{ /* translators: %d: rules score */ sprintf( __( 'Rules: %d', 'dev3-ai-entry-insights' ), entry.rule_score ) }</span>
							) }
						</div>
						{ entry.ai_state === 'failed' && entry.ai_error && <Notice tone="warning">{ entry.ai_error }</Notice> }
						<div>
							<AiButton size="sm" loading={ busy === 'analyze' } disabled={ ! aiReady } onClick={ analyze } loadingText={ __( 'Analysing…', 'dev3-ai-entry-insights' ) }>
								{ entry.insight_src === 'ai' ? __( 'Re-analyze with DEV3 AI', 'dev3-ai-entry-insights' ) : __( 'Analyze with DEV3 AI', 'dev3-ai-entry-insights' ) }
							</AiButton>
						</div>
						<ErrorNotice error={ aiError } onDismiss={ () => setAiError( null ) } />
					</section>

					<section className="dev3ei-section">
						<h3>{ __( 'Tags', 'dev3-ai-entry-insights' ) }</h3>
						{ entry.suggested_tags.length > 0 && (
							<div className="dev3ei-suggest">
								<span className="dev3ei-muted">{ __( 'Suggested by DEV3 AI:', 'dev3-ai-entry-insights' ) }</span>
								<span className="dev3ei-tags">
									{ entry.suggested_tags.map( ( t ) => (
										<button key={ t } type="button" className="dev3ei-tag dev3ei-tag--suggest" onClick={ () => acceptTags( [ t ] ) } aria-label={ /* translators: %s: tag */ sprintf( __( 'Add suggested tag %s', 'dev3-ai-entry-insights' ), t ) }>
											+ { t }
										</button>
									) ) }
								</span>
								<span className="dev3ei-actions">
									<Button size="sm" variant="secondary" icon="check" onClick={ () => acceptTags( entry.suggested_tags ) }>
										{ __( 'Accept all', 'dev3-ai-entry-insights' ) }
									</Button>
									<Button size="sm" variant="ghost" onClick={ () => acceptTags( entry.suggested_tags, true ) }>
										{ __( 'Dismiss', 'dev3-ai-entry-insights' ) }
									</Button>
								</span>
							</div>
						) }
						{ entry.tags.length ? <Tags tags={ entry.tags } onRemove={ ( t ) => update( { tags: entry.tags.filter( ( x ) => x !== t ) } ) } /> : <p className="dev3ei-muted">{ __( 'No tags yet.', 'dev3-ai-entry-insights' ) }</p> }
						<form className="dev3ei-inlineform" onSubmit={ addTag }>
							<TextField label={ __( 'Add a tag', 'dev3-ai-entry-insights' ) } hideLabel value={ tagInput } onChange={ setTagInput } placeholder={ __( 'Add a tag…', 'dev3-ai-entry-insights' ) } />
							<Button type="submit" size="sm" variant="secondary" icon="plus" disabled={ ! tagInput.trim() }>
								{ __( 'Add', 'dev3-ai-entry-insights' ) }
							</Button>
						</form>
					</section>

					<section className="dev3ei-section">
						<h3>{ __( 'Answers', 'dev3-ai-entry-insights' ) }</h3>
						<dl className="dev3ei-answers">
							{ entry.fields.map( ( f ) => (
								<div key={ f.key }>
									<dt>{ f.label }</dt>
									<dd>{ f.value }</dd>
								</div>
							) ) }
						</dl>
						{ entry.page_url && (
							<p className="dev3ei-muted">
								{ __( 'Sent from', 'dev3-ai-entry-insights' ) }{ ' ' }
								<a className="dev3ei-link" href={ entry.page_url } target="_blank" rel="noreferrer">
									{ entry.page_url.replace( /^https?:\/\//, '' ) }
								</a>
							</p>
						) }
					</section>

					<section className="dev3ei-section">
						<div className="dev3ei-section__head">
							<h3>{ __( 'Reply', 'dev3-ai-entry-insights' ) }</h3>
							{ entry.replied_at && <Badge tone="success">{ /* translators: %s: time */ sprintf( __( 'Replied %s', 'dev3-ai-entry-insights' ), ago( entry.replied_at ) ) }</Badge> }
						</div>
						{ ! entry.email ? (
							<p className="dev3ei-muted">{ __( 'This entry has no email address, so there is no one to reply to.', 'dev3-ai-entry-insights' ) }</p>
						) : (
							<>
								<div className="dev3ei-aibox">
									<div className="dev3ei-replyopts">
										<Select label={ __( 'Tone', 'dev3-ai-entry-insights' ) } value={ tone } onChange={ setTone } options={ TONES } />
										<Textarea label={ __( 'Notes for this reply (optional)', 'dev3-ai-entry-insights' ) } value={ notes } onChange={ setNotes } rows={ 2 } maxLength={ 2000 } help={ __( 'Facts DEV3 AI may use, e.g. "We can start on 4 May, the price is $2,400".', 'dev3-ai-entry-insights' ) } />
									</div>
									<AiButton loading={ busy === 'draft' } disabled={ ! aiReady } onClick={ draftReply }>
										{ __( 'Draft reply with DEV3 AI', 'dev3-ai-entry-insights' ) }
									</AiButton>
								</div>
								{ draft && (
									<AiDraftReview
										title={ __( 'DEV3 AI reply draft', 'dev3-ai-entry-insights' ) }
										fields={ REPLY_FIELDS }
										draft={ { subject: draft.subject, body: draft.body } }
										onAccept={ send }
										onReject={ () => setDraft( null ) }
										onRegenerate={ draftReply }
										regenerating={ busy === 'draft' }
										accepting={ busy === 'send' }
										acceptLabel={ /* translators: %s: email */ sprintf( __( 'Send to %s', 'dev3-ai-entry-insights' ), entry.email ) }
										meta={ { model: draft.model, tokens: draft.tokens, cached: draft.cached } }
									/>
								) }
								{ ! draft && (
									<div className="dev3ei-compose">
										{ templates.length > 0 && (
											<div className="dev3ei-inlineform">
												<Select label={ __( 'Reply template', 'dev3-ai-entry-insights' ) } hideLabel value={ template } onChange={ setTemplate } placeholder={ __( 'Start from a template…', 'dev3-ai-entry-insights' ) } options={ templates } />
												<Button size="sm" variant="secondary" disabled={ ! template } onClick={ useTemplate }>
													{ __( 'Use', 'dev3-ai-entry-insights' ) }
												</Button>
											</div>
										) }
										{ compose ? (
											<>
												<TextField label={ __( 'Subject', 'dev3-ai-entry-insights' ) } value={ compose.subject } onChange={ ( v ) => setCompose( { ...compose, subject: v } ) } />
												<Textarea label={ __( 'Message', 'dev3-ai-entry-insights' ) } value={ compose.body } onChange={ ( v ) => setCompose( { ...compose, body: v } ) } rows={ 8 } />
												<div className="dev3ei-actions">
													<Button variant="primary" icon="check" loading={ busy === 'send' } disabled={ ! compose.subject.trim() || ! compose.body.trim() } onClick={ () => send( compose ) }>
														{ /* translators: %s: email */ sprintf( __( 'Send to %s', 'dev3-ai-entry-insights' ), entry.email ) }
													</Button>
													<Button variant="ghost" onClick={ () => setCompose( null ) }>
														{ __( 'Cancel', 'dev3-ai-entry-insights' ) }
													</Button>
												</div>
											</>
										) : (
											<Button variant="link" onClick={ () => setCompose( { subject: `Re: ${ entry.form_title }`, body: '' } ) }>
												{ __( 'Write a reply yourself', 'dev3-ai-entry-insights' ) }
											</Button>
										) }
									</div>
								) }
							</>
						) }
					</section>

					<section className="dev3ei-section">
						<h3>{ __( 'Internal note', 'dev3-ai-entry-insights' ) }</h3>
						<Textarea label={ __( 'Internal note', 'dev3-ai-entry-insights' ) } hideLabel value={ note } onChange={ setNote } rows={ 3 } maxLength={ 5000 } placeholder={ __( 'Only your team sees this.', 'dev3-ai-entry-insights' ) } />
						<div className="dev3ei-actions">
							<Button size="sm" variant="secondary" disabled={ note === ( entry.note || '' ) } loading={ busy === 'update' } onClick={ () => update( { note }, __( 'Note saved.', 'dev3-ai-entry-insights' ) ) }>
								{ __( 'Save note', 'dev3-ai-entry-insights' ) }
							</Button>
							{ entry.can_gf_note && (
								<Button size="sm" variant="ghost" onClick={ gfNote }>
									{ __( 'Add insights as Gravity Forms note', 'dev3-ai-entry-insights' ) }
								</Button>
							) }
						</div>
					</section>

					<section className="dev3ei-section">
						<h3>{ __( 'History', 'dev3-ai-entry-insights' ) }</h3>
						<ol className="dev3ei-timeline">
							{ entry.history.map( ( h, i ) => (
								<li key={ i }>
									<strong>{ HISTORY[ h.event ] || h.event }</strong> · { byLabel( h.by ) } · { ago( h.at ) }
									{ h.detail && h.event !== 'captured' && <span className="dev3ei-timeline__note">{ h.detail }</span> }
								</li>
							) ) }
						</ol>
					</section>
				</div>
			) }
			<Modal
				open={ confirmDelete }
				onClose={ () => setConfirmDelete( false ) }
				size="sm"
				title={ __( 'Delete this entry?', 'dev3-ai-entry-insights' ) }
				description={ __( 'It is removed from DEV3 AI Entry Insights. A copy stored by Gravity Forms or WPForms Pro is not touched.', 'dev3-ai-entry-insights' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setConfirmDelete( false ) } data-autofocus>
							{ __( 'Cancel', 'dev3-ai-entry-insights' ) }
						</Button>
						<Button variant="danger" onClick={ remove }>
							{ __( 'Delete', 'dev3-ai-entry-insights' ) }
						</Button>
					</>
				}
			/>
		</Drawer>
	);
}
