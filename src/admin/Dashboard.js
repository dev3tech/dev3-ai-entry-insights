import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { AiButton, Badge, Button, Card, Columns, EmptyState, ErrorNotice, Notice, PageHeader, Select, SkeletonCard, Stat, StatGrid, formatNumber, useToast } from '../core3/ui';
import { HBars, Histogram, MoodBar, SentimentBars } from './charts';
import { CATEGORIES, SOURCES, SentimentBadge, ScorePill, UrgencyBadge, ago } from './helpers';

const PERIODS = [
	{ value: '7', label: __( 'Last 7 days', 'dev3-ai-entry-insights' ) },
	{ value: '30', label: __( 'Last 30 days', 'dev3-ai-entry-insights' ) },
	{ value: '90', label: __( 'Last 90 days', 'dev3-ai-entry-insights' ) },
];

/**
 * Trend as a kit Stat trend.
 *
 * @param {number} now  Current.
 * @param {number} prev Previous.
 * @param {boolean} upIsGood Up is good.
 * @return {Object|undefined} Trend.
 */
const trend = ( now, prev, upIsGood = true ) => {
	if ( ! prev ) {
		return undefined;
	}
	const pct = Math.round( ( ( now - prev ) / prev ) * 100 );
	const direction = pct > 0 ? 'up' : pct < 0 ? 'down' : 'flat';
	return { value: `${ pct > 0 ? '+' : '' }${ pct }%`, direction, good: direction === 'flat' ? true : ( direction === 'up' ) === upIsGood };
};

/**
 * Digest body.
 *
 * @param {Object} props        Props.
 * @param {Object} props.digest Digest.
 * @return {Element} Digest.
 */
export function DigestView( { digest } ) {
	const lists = [
		[ 'opportunities', __( 'Opportunities', 'dev3-ai-entry-insights' ), 'success' ],
		[ 'risks', __( 'Risks', 'dev3-ai-entry-insights' ), 'danger' ],
		[ 'actions', __( 'Suggested actions', 'dev3-ai-entry-insights' ), 'brand' ],
	];
	return (
		<div className="dev3ei-digest">
			{ digest.headline && <p className="dev3ei-digest__headline">{ digest.headline }</p> }
			{ digest.summary && <p className="dev3ei-digest__summary">{ digest.summary }</p> }
			{ digest.themes && digest.themes.length > 0 && (
				<div className="dev3ei-digest__themes">
					{ digest.themes.map( ( t ) => (
						<span key={ t.label } className="dev3ei-theme">
							<strong>{ t.label }</strong>
							<span className="dev3ei-muted">{ Math.round( t.share * 100 ) }%</span>
							<Badge tone={ t.sentiment >= 0.25 ? 'success' : t.sentiment <= -0.25 ? 'danger' : 'neutral' } dot>
								{ t.sentiment >= 0.25 ? __( 'Positive', 'dev3-ai-entry-insights' ) : t.sentiment <= -0.25 ? __( 'Negative', 'dev3-ai-entry-insights' ) : __( 'Mixed', 'dev3-ai-entry-insights' ) }
							</Badge>
						</span>
					) ) }
				</div>
			) }
			<div className="dev3ei-digest__lists">
				{ lists.map( ( [ key, label ] ) =>
					digest[ key ] && digest[ key ].length ? (
						<div key={ key }>
							<h4 className="dev3ei-h4">{ label }</h4>
							<ul className="dev3ei-bullets">
								{ digest[ key ].map( ( item, i ) => (
									<li key={ i }>{ item }</li>
								) ) }
							</ul>
						</div>
					) : null
				) }
			</div>
		</div>
	);
}

/**
 * Insights dashboard.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.navigate Router.
 * @param {Function} props.onUsage  AI used.
 * @return {Element} Dashboard.
 */
export default function Dashboard( { settings, navigate, onUsage } ) {
	const toast = useToast();
	const [ days, setDays ] = useState( '30' );
	const [ form, setForm ] = useState( '' );
	const [ forms, setForms ] = useState( [] );
	const [ stats, setStats ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ draftError, setDraftError ] = useState( null );

	const load = () => {
		setError( null );
		request( `stats?days=${ days }&form=${ encodeURIComponent( form ) }` ).then( setStats ).catch( setError );
	};
	useEffect( load, [ days, form ] );
	useEffect( () => {
		request( 'forms' )
			.then( ( res ) => setForms( res.forms || [] ) )
			.catch( () => {} );
	}, [] );

	const aiReady = settings.api_key_set && settings.ai_enabled !== false && config.canGenerate;
	const generate = () => {
		setBusy( true );
		setDraftError( null );
		request( 'digest', { method: 'POST', data: { days: Number( days ), form } } )
			.then( setDraft )
			.catch( setDraftError )
			.finally( () => {
				setBusy( false );
				onUsage();
			} );
	};
	const pin = () =>
		request( 'digest/pin', { method: 'POST', data: { digest: draft.digest, days: draft.days, form: draft.form } } )
			.then( ( res ) => {
				setStats( ( s ) => ( { ...s, digest: res.pinned } ) );
				setDraft( null );
				toast.success( __( 'Digest pinned to the dashboard.', 'dev3-ai-entry-insights' ) );
			} )
			.catch( ( e ) => toast.error( e ) );
	const unpin = () =>
		request( 'digest', { method: 'DELETE' } )
			.then( () => setStats( ( s ) => ( { ...s, digest: null } ) ) )
			.catch( ( e ) => toast.error( e ) );

	const formOptions = [ { value: '', label: __( 'All forms', 'dev3-ai-entry-insights' ) } ].concat( forms.map( ( f ) => ( { value: f.key, label: `${ f.title } · ${ SOURCES[ f.source ] || f.source }` } ) ) );
	const noForms = config.adapters && ! config.adapters.some( ( a ) => a.active );

	return (
		<>
			<PageHeader
				title={ __( 'Insights', 'dev3-ai-entry-insights' ) }
				description={ __( 'What people ask for, how they feel and which entries are worth your time.', 'dev3-ai-entry-insights' ) }
				actions={
					<div className="dev3ei-filters">
						<Select label={ __( 'Period', 'dev3-ai-entry-insights' ) } hideLabel value={ days } onChange={ setDays } options={ PERIODS } />
						<Select label={ __( 'Form', 'dev3-ai-entry-insights' ) } hideLabel value={ form } onChange={ setForm } options={ formOptions } />
					</div>
				}
			/>
			{ noForms && (
				<Notice tone="warning" title={ __( 'No supported form plugin is active', 'dev3-ai-entry-insights' ) } actions={ <Button size="sm" href="#/forms">{ __( 'See supported plugins', 'dev3-ai-entry-insights' ) }</Button> }>
					{ __( 'Activate Gravity Forms, WPForms (Lite or Pro) or Contact Form 7 and new submissions appear here automatically.', 'dev3-ai-entry-insights' ) }
				</Notice>
			) }
			{ ! settings.api_key_set && (
				<Notice
					tone="ai"
					title={ __( 'Connect DEV3 AI for summaries, intent and smarter lead scores', 'dev3-ai-entry-insights' ) }
					actions={
						<Button size="sm" variant="ai" href="#/api">
							{ __( 'Add API key', 'dev3-ai-entry-insights' ) }
						</Button>
					}
				>
					{ __( 'Rule-based scores, sentiment, urgency, tags, alerts and reply templates already work without AI.', 'dev3-ai-entry-insights' ) }
				</Notice>
			) }
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! stats && ! error && <SkeletonCard lines={ 6 } /> }
			{ stats && (
				<>
					<StatGrid min={ 200 }>
						<Stat label={ __( 'Entries', 'dev3-ai-entry-insights' ) } value={ formatNumber( stats.total ) } icon="inbox" tone="brand" trend={ trend( stats.total, stats.prev_total ) } hint={ __( 'vs. previous period', 'dev3-ai-entry-insights' ) } />
						<Stat label={ __( 'Average lead score', 'dev3-ai-entry-insights' ) } value={ stats.total ? String( stats.avg_score ) : '–' } icon="chart" tone="neutral" trend={ trend( stats.avg_score, stats.prev_avg ) } />
						<Stat
							label={ __( 'Hot leads', 'dev3-ai-entry-insights' ) }
							value={ formatNumber( stats.hot ) }
							icon="bolt"
							tone="success"
							/* translators: %d: hot lead score threshold */
							hint={ sprintf( __( 'Score %d or more', 'dev3-ai-entry-insights' ), stats.hot_threshold ) }
						/>
						<Stat label={ __( 'Negative', 'dev3-ai-entry-insights' ) } value={ `${ stats.negative_pct }%` } icon="alert" tone={ stats.negative_pct >= 20 ? 'danger' : 'neutral' } hint={ __( 'of entries', 'dev3-ai-entry-insights' ) } />
						<Stat label={ __( 'Needs attention', 'dev3-ai-entry-insights' ) } value={ formatNumber( stats.attention_total ) } icon="clock" tone={ stats.attention_total ? 'warning' : 'success' } hint={ __( 'Open, urgent, unhappy or hot', 'dev3-ai-entry-insights' ) } />
						<Stat label={ __( 'Reply rate', 'dev3-ai-entry-insights' ) } value={ `${ stats.reply_rate }%` } icon="check" tone="neutral" hint={ __( 'Replied from this plugin', 'dev3-ai-entry-insights' ) } />
					</StatGrid>
					{ stats.pending > 0 && (
						<Notice tone="ai">
							{ /* translators: %d: entries */ sprintf( __( '%d entries are waiting for DEV3 AI analysis. They are processed in the background and while this screen is open.', 'dev3-ai-entry-insights' ), stats.pending ) }
						</Notice>
					) }

					<Card
						tone="ai"
						title={ __( 'Trend digest', 'dev3-ai-entry-insights' ) }
						description={ __( 'A short read of what changed in the period: themes, opportunities, risks and next steps.', 'dev3-ai-entry-insights' ) }
						actions={
							stats.digest && ! draft ? (
								<Button size="sm" variant="ghost" icon="x" onClick={ unpin }>
									{ __( 'Unpin', 'dev3-ai-entry-insights' ) }
								</Button>
							) : null
						}
					>
						{ draft ? (
							<div className="dev3ei-draftbox">
								<div className="dev3ei-draftbox__head">
									<Badge tone="warning">{ __( 'Not saved yet', 'dev3-ai-entry-insights' ) }</Badge>
									<span className="dev3ei-muted">
										{ draft.model }
										{ draft.cached ? ` · ${ __( 'cached', 'dev3-ai-entry-insights' ) }` : '' }
									</span>
								</div>
								<DigestView digest={ draft.digest } />
								<div className="dev3ei-actions">
									<Button variant="primary" icon="check" onClick={ pin }>
										{ __( 'Pin to dashboard', 'dev3-ai-entry-insights' ) }
									</Button>
									<Button variant="secondary" icon="refresh" loading={ busy } onClick={ generate }>
										{ __( 'Regenerate', 'dev3-ai-entry-insights' ) }
									</Button>
									<Button variant="ghost" onClick={ () => setDraft( null ) }>
										{ __( 'Discard', 'dev3-ai-entry-insights' ) }
									</Button>
								</div>
							</div>
						) : stats.digest ? (
							<>
								<DigestView digest={ stats.digest.digest } />
								<p className="dev3ei-muted">
									{
										/* translators: 1: days, 2: relative time */
										sprintf( __( 'Last %1$d days · pinned %2$s', 'dev3-ai-entry-insights' ), stats.digest.days, ago( stats.digest.pinned_at ) )
									}
								</p>
								{ aiReady && (
									<div>
										<AiButton size="sm" loading={ busy } onClick={ generate }>
											{ __( 'Refresh with DEV3 AI', 'dev3-ai-entry-insights' ) }
										</AiButton>
									</div>
								) }
							</>
						) : (
							<div className="dev3ei-aibox">
								<p className="dev3ei-muted">{ aiReady ? __( 'DEV3 AI reads the scores and one-line summaries of the period (never names or emails) and writes a digest you can pin here.', 'dev3-ai-entry-insights' ) : __( 'Add a Google Gemini API key in API Config to generate trend digests.', 'dev3-ai-entry-insights' ) }</p>
								<AiButton loading={ busy } disabled={ ! aiReady || stats.total < 3 } onClick={ generate }>
									{ __( 'Summarize trends with DEV3 AI', 'dev3-ai-entry-insights' ) }
								</AiButton>
							</div>
						) }
						<ErrorNotice error={ draftError } onRetry={ generate } onDismiss={ () => setDraftError( null ) } />
					</Card>

					{ stats.total === 0 ? (
						<Card>
							<EmptyState
								icon="inbox"
								title={ __( 'No entries in this period', 'dev3-ai-entry-insights' ) }
								description={ __( 'New Gravity Forms, WPForms and Contact Form 7 submissions are captured automatically. Import older Gravity Forms or WPForms Pro entries from the Forms screen.', 'dev3-ai-entry-insights' ) }
								action={ <Button variant="primary" href="#/forms">{ __( 'Open Forms', 'dev3-ai-entry-insights' ) }</Button> }
							/>
						</Card>
					) : (
						<>
							<Columns min={ 320 }>
								<Card title={ __( 'Entries per day', 'dev3-ai-entry-insights' ) } description={ __( 'Coloured by the sender\'s sentiment.', 'dev3-ai-entry-insights' ) }>
									<SentimentBars data={ stats.series } />
								</Card>
								<Card title={ __( 'Lead score distribution', 'dev3-ai-entry-insights' ) } description={ __( 'Hot leads are highlighted.', 'dev3-ai-entry-insights' ) }>
									<Histogram data={ stats.scores } hotFrom={ Math.floor( stats.hot_threshold / 20 ) } />
								</Card>
							</Columns>
							<Columns min={ 300 }>
								<Card title={ __( 'What people want', 'dev3-ai-entry-insights' ) } description={ __( 'Categories of the period. Click to see the entries.', 'dev3-ai-entry-insights' ) }>
									<HBars items={ Object.entries( stats.categories ).map( ( [ key, value ] ) => ( { key, value, label: CATEGORIES[ key ] || key, tone: key === 'complaint' || key === 'spam' ? 'danger' : 'brand' } ) ) } onPick={ ( key ) => navigate( `entries?category=${ key }` ) } />
								</Card>
								<Card title={ __( 'Top intents', 'dev3-ai-entry-insights' ) } description={ __( 'Written by DEV3 AI for each entry.', 'dev3-ai-entry-insights' ) }>
									{ stats.intents.length ? (
										<HBars items={ stats.intents.map( ( i ) => ( { key: i.label, value: i.count, label: i.label } ) ) } tone="success" />
									) : (
										<EmptyState compact icon="sparkles" title={ __( 'No intents yet', 'dev3-ai-entry-insights' ) } description={ __( 'Intents appear once DEV3 AI has analysed entries.', 'dev3-ai-entry-insights' ) } />
									) }
								</Card>
								<Card title={ __( 'Tags', 'dev3-ai-entry-insights' ) } description={ __( 'From your auto-tag rules and accepted AI tags.', 'dev3-ai-entry-insights' ) }>
									{ Object.keys( stats.tags ).length ? (
										<div className="dev3ei-tagcloud">
											{ Object.entries( stats.tags ).map( ( [ tag, n ] ) => (
												<button key={ tag } type="button" className="dev3ei-tag dev3ei-tag--btn" onClick={ () => navigate( `entries?tag=${ tag }` ) }>
													#{ tag } <span className="dev3ei-muted">{ n }</span>
												</button>
											) ) }
										</div>
									) : (
										<EmptyState compact icon="list" title={ __( 'No tags yet', 'dev3-ai-entry-insights' ) } description={ __( 'Add auto-tag rules in Rules.', 'dev3-ai-entry-insights' ) } />
									) }
								</Card>
							</Columns>
							<Columns min={ 320 }>
								<Card
									title={ __( 'Needs attention', 'dev3-ai-entry-insights' ) }
									flush
									actions={
										<Button size="sm" variant="ghost" iconRight="chevronRight" onClick={ () => navigate( 'entries?view=attention' ) }>
											{ __( 'All', 'dev3-ai-entry-insights' ) }
										</Button>
									}
								>
									{ stats.attention.length ? (
										<ul className="dev3ei-list">
											{ stats.attention.map( ( e ) => (
												<li key={ e.id }>
													<button type="button" className="dev3ei-list__row" onClick={ () => navigate( `entries/${ e.id }` ) }>
														<span className="dev3ei-list__main">
															<span className="dev3ei-strong dev3ei-clamp">{ e.summary || e.intent || e.form_title }</span>
															<span className="dev3ei-muted">
																{ e.name || __( 'Unknown sender', 'dev3-ai-entry-insights' ) } · { e.form_title } · { ago( e.created_at ) }
															</span>
															<span className="dev3ei-chips">
																<SentimentBadge value={ e.sentiment } />
																{ e.urgency !== 'low' && <UrgencyBadge value={ e.urgency } /> }
															</span>
														</span>
														<span className="dev3ei-list__side">
															<ScorePill score={ e.lead_score } hot={ stats.hot_threshold } />
														</span>
													</button>
												</li>
											) ) }
										</ul>
									) : (
										<EmptyState compact icon="check" title={ __( 'All caught up', 'dev3-ai-entry-insights' ) } description={ __( 'No open urgent, unhappy or hot entries.', 'dev3-ai-entry-insights' ) } />
									) }
								</Card>
								<Card title={ __( 'Forms', 'dev3-ai-entry-insights' ) } flush>
									<ul className="dev3ei-list">
										{ stats.forms.map( ( f ) => (
											<li key={ f.key }>
												<button type="button" className="dev3ei-list__row" onClick={ () => navigate( `entries?form=${ encodeURIComponent( f.key ) }` ) }>
													<span className="dev3ei-list__main">
														<span className="dev3ei-strong">{ f.title }</span>
														<span className="dev3ei-muted">
															{ SOURCES[ f.source ] || f.source } · { /* translators: %d: entries */ sprintf( __( '%d entries', 'dev3-ai-entry-insights' ), f.total ) } · { /* translators: %d: hot leads */ sprintf( __( '%d hot', 'dev3-ai-entry-insights' ), f.hot ) }
														</span>
														<MoodBar positive={ f.positive } neutral={ f.neutral } negative={ f.negative } />
													</span>
													<span className="dev3ei-list__side">
														<ScorePill score={ f.avg_score } hot={ stats.hot_threshold } />
														<span className="dev3ei-muted dev3ei-small">{ __( 'avg', 'dev3-ai-entry-insights' ) }</span>
													</span>
												</button>
											</li>
										) ) }
									</ul>
								</Card>
							</Columns>
						</>
					) }
				</>
			) }
		</>
	);
}
