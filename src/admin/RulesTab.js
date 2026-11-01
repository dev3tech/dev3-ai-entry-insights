import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, Columns, ErrorNotice, FormGrid, IconButton, NumberField, PageHeader, Select, TextField, Textarea } from '../core3/ui';
import { CATEGORIES, SENTIMENT, SOURCES, ScorePill, SentimentBadge, Tags, URGENCY, UrgencyBadge, options, useSettingsForm } from './helpers';

const KEYS = [ 'score_mode', 'hot_threshold', 'score_base', 'signals', 'keyword_rules', 'tag_rules', 'auto_apply_ai_tags' ];

export const SIGNALS = {
	phone: __( 'Left a phone number', 'dev3-ai-entry-insights' ),
	company_email: __( 'Uses a company email (not Gmail, Outlook…)', 'dev3-ai-entry-insights' ),
	company: __( 'Filled a company / organisation field', 'dev3-ai-entry-insights' ),
	budget: __( 'Filled a budget / price field', 'dev3-ai-entry-insights' ),
	buying: __( 'Mentions buying words (quote, pricing, demo, project…)', 'dev3-ai-entry-insights' ),
	urgent: __( 'Has a tight timeline (ASAP, today, deadline…)', 'dev3-ai-entry-insights' ),
	long_message: __( 'Wrote a detailed message (280+ characters)', 'dev3-ai-entry-insights' ),
	negative: __( 'Sounds unhappy', 'dev3-ai-entry-insights' ),
};

const WHEN = {
	keyword: __( 'Answers contain (comma-separated)', 'dev3-ai-entry-insights' ),
	category: __( 'Category is', 'dev3-ai-entry-insights' ),
	sentiment: __( 'Sentiment is', 'dev3-ai-entry-insights' ),
	urgency: __( 'Urgency is', 'dev3-ai-entry-insights' ),
	score: __( 'Lead score is at least', 'dev3-ai-entry-insights' ),
	source: __( 'Form plugin is', 'dev3-ai-entry-insights' ),
	form: __( 'Form key is (e.g. wpforms:12)', 'dev3-ai-entry-insights' ),
};

const VALUE_OPTIONS = {
	category: options( CATEGORIES ),
	sentiment: options( SENTIMENT ),
	urgency: options( URGENCY ),
	source: options( SOURCES ),
};

/**
 * Scoring and auto-tag rules with a live preview.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.save     Save.
 * @return {Element} Screen.
 */
export default function RulesTab( { settings, save } ) {
	const { form, set, dirty, busy, submit, reset } = useSettingsForm( settings, save, KEYS );
	const [ sample, setSample ] = useState( { text: '', email: '', phone: '', company: '' } );
	const [ result, setResult ] = useState( null );
	const [ testing, setTesting ] = useState( false );
	const [ testError, setTestError ] = useState( null );

	const signals = { ...form.signals };
	const setSignal = ( key ) => ( v ) => set( 'signals' )( { ...signals, [ key ]: v === '' ? 0 : v } );
	const keywords = form.keyword_rules || [];
	const tagRules = form.tag_rules || [];
	const setRow = ( list, key, i, patch ) => set( key )( list.map( ( r, j ) => ( j === i ? { ...r, ...patch } : r ) ) );
	const removeRow = ( list, key, i ) => set( key )( list.filter( ( r, j ) => j !== i ) );

	const preview = () => {
		setTesting( true );
		setTestError( null );
		// Save first so the preview uses the rules on screen.
		const run = () => request( 'rules/preview', { method: 'POST', data: sample } ).then( setResult ).catch( setTestError );
		( dirty ? submit().then( run ) : run() ).finally( () => setTesting( false ) );
	};

	const footer = (
		<>
			<Button variant="ghost" onClick={ reset } disabled={ ! dirty || busy }>
				{ __( 'Reset', 'dev3-ai-entry-insights' ) }
			</Button>
			<Button variant="primary" type="submit" loading={ busy } disabled={ ! dirty }>
				{ __( 'Save rules', 'dev3-ai-entry-insights' ) }
			</Button>
		</>
	);

	return (
		<form className="dev3ei-form" onSubmit={ submit }>
			<PageHeader title={ __( 'Rules', 'dev3-ai-entry-insights' ) } description={ __( 'How lead scores and tags are calculated. Rules always run, with or without AI.', 'dev3-ai-entry-insights' ) } />
			<Card title={ __( 'Lead score', 'dev3-ai-entry-insights' ) } footer={ footer }>
				<FormGrid>
					<Select
						label={ __( 'Score source', 'dev3-ai-entry-insights' ) }
						value={ form.score_mode }
						onChange={ set( 'score_mode' ) }
						options={ [
							{ value: 'ai', label: __( 'DEV3 AI score (rules when AI is off)', 'dev3-ai-entry-insights' ) },
							{ value: 'blend', label: __( 'Average of DEV3 AI and rules', 'dev3-ai-entry-insights' ) },
							{ value: 'rules', label: __( 'Rules only (AI still writes summaries)', 'dev3-ai-entry-insights' ) },
						] }
					/>
					<NumberField label={ __( 'Hot lead from score', 'dev3-ai-entry-insights' ) } value={ form.hot_threshold } min={ 1 } max={ 100 } onChange={ set( 'hot_threshold' ) } help={ __( 'Used for the Hot leads view, alerts and the dashboard.', 'dev3-ai-entry-insights' ) } />
					<NumberField label={ __( 'Starting score', 'dev3-ai-entry-insights' ) } value={ form.score_base } min={ 0 } max={ 100 } onChange={ set( 'score_base' ) } help={ __( 'Every entry starts here before signals add or remove points.', 'dev3-ai-entry-insights' ) } />
				</FormGrid>
				<h4 className="dev3ei-h4 dev3ei-mt">{ __( 'Signals (points)', 'dev3-ai-entry-insights' ) }</h4>
				<ul className="dev3ei-signals">
					{ Object.keys( SIGNALS ).map( ( key ) => (
						<li key={ key }>
							<span>{ SIGNALS[ key ] }</span>
							<NumberField label={ SIGNALS[ key ] } hideLabel value={ signals[ key ] ?? 0 } min={ -100 } max={ 100 } onChange={ setSignal( key ) } />
						</li>
					) ) }
				</ul>
				<h4 className="dev3ei-h4 dev3ei-mt">{ __( 'Keyword points', 'dev3-ai-entry-insights' ) }</h4>
				<p className="dev3ei-muted">{ __( 'Add or remove points when the answers contain a word or phrase, e.g. "enterprise" +20 or "student" −10.', 'dev3-ai-entry-insights' ) }</p>
				<ul className="dev3ei-rows">
					{ keywords.map( ( r, i ) => (
						<li key={ i } className="dev3ei-row">
							<TextField label={ __( 'Keyword', 'dev3-ai-entry-insights' ) } value={ r.keyword } onChange={ ( v ) => setRow( keywords, 'keyword_rules', i, { keyword: v } ) } />
							<NumberField label={ __( 'Points', 'dev3-ai-entry-insights' ) } value={ r.points } min={ -100 } max={ 100 } onChange={ ( v ) => setRow( keywords, 'keyword_rules', i, { points: v === '' ? 0 : v } ) } />
							<IconButton icon="trash" label={ __( 'Remove keyword rule', 'dev3-ai-entry-insights' ) } onClick={ () => removeRow( keywords, 'keyword_rules', i ) } />
						</li>
					) ) }
				</ul>
				<Button size="sm" variant="secondary" icon="plus" onClick={ () => set( 'keyword_rules' )( [ ...keywords, { keyword: '', points: 10 } ] ) }>
					{ __( 'Add keyword', 'dev3-ai-entry-insights' ) }
				</Button>
			</Card>

			<Card title={ __( 'Auto-tags', 'dev3-ai-entry-insights' ) } description={ __( 'Tags added automatically when a condition matches, on capture and after every analysis.', 'dev3-ai-entry-insights' ) } footer={ footer }>
				<ul className="dev3ei-rows">
					{ tagRules.map( ( r, i ) => (
						<li key={ i } className="dev3ei-row dev3ei-row--tag">
							<Select label={ __( 'When', 'dev3-ai-entry-insights' ) } value={ r.when } onChange={ ( v ) => setRow( tagRules, 'tag_rules', i, { when: v, value: VALUE_OPTIONS[ v ] ? VALUE_OPTIONS[ v ][ 0 ].value : '' } ) } options={ options( WHEN ) } />
							{ VALUE_OPTIONS[ r.when ] ? (
								<Select label={ __( 'Value', 'dev3-ai-entry-insights' ) } value={ r.value } onChange={ ( v ) => setRow( tagRules, 'tag_rules', i, { value: v } ) } options={ VALUE_OPTIONS[ r.when ] } />
							) : (
								<TextField label={ __( 'Value', 'dev3-ai-entry-insights' ) } value={ r.value } onChange={ ( v ) => setRow( tagRules, 'tag_rules', i, { value: v } ) } />
							) }
							<TextField label={ __( 'Add tag', 'dev3-ai-entry-insights' ) } prefix="#" value={ r.tag } onChange={ ( v ) => setRow( tagRules, 'tag_rules', i, { tag: v } ) } />
							<IconButton icon="trash" label={ __( 'Remove tag rule', 'dev3-ai-entry-insights' ) } onClick={ () => removeRow( tagRules, 'tag_rules', i ) } />
						</li>
					) ) }
				</ul>
				<Button size="sm" variant="secondary" icon="plus" onClick={ () => set( 'tag_rules' )( [ ...tagRules, { when: 'keyword', value: '', tag: '' } ] ) }>
					{ __( 'Add tag rule', 'dev3-ai-entry-insights' ) }
				</Button>
				<div className="dev3ei-mt">
					<Select
						label={ __( 'Tags suggested by DEV3 AI', 'dev3-ai-entry-insights' ) }
						value={ form.auto_apply_ai_tags ? '1' : '0' }
						onChange={ ( v ) => set( 'auto_apply_ai_tags' )( v === '1' ) }
						options={ [
							{ value: '0', label: __( 'Keep as suggestions until someone accepts them', 'dev3-ai-entry-insights' ) },
							{ value: '1', label: __( 'Apply them automatically', 'dev3-ai-entry-insights' ) },
						] }
					/>
				</div>
			</Card>

			<Columns min={ 300 }>
				<Card title={ __( 'Try the rules', 'dev3-ai-entry-insights' ) } description={ __( 'Paste a sample submission to see the rule-based result. Nothing is saved and no AI is used.', 'dev3-ai-entry-insights' ) }>
					<Textarea label={ __( 'Message', 'dev3-ai-entry-insights' ) } value={ sample.text } onChange={ ( v ) => setSample( { ...sample, text: v } ) } rows={ 4 } placeholder={ __( 'Hi, we need a quote for a new website by next week. Budget around $5k.', 'dev3-ai-entry-insights' ) } />
					<FormGrid>
						<TextField label={ __( 'Email', 'dev3-ai-entry-insights' ) } type="email" value={ sample.email } onChange={ ( v ) => setSample( { ...sample, email: v } ) } placeholder="anna@acme.com" />
						<TextField label={ __( 'Phone', 'dev3-ai-entry-insights' ) } value={ sample.phone } onChange={ ( v ) => setSample( { ...sample, phone: v } ) } />
						<TextField label={ __( 'Company', 'dev3-ai-entry-insights' ) } value={ sample.company } onChange={ ( v ) => setSample( { ...sample, company: v } ) } />
					</FormGrid>
					<div>
						<Button variant="secondary" icon="bolt" loading={ testing } disabled={ ! sample.text.trim() } onClick={ preview }>
							{ dirty ? __( 'Save and test', 'dev3-ai-entry-insights' ) : __( 'Test', 'dev3-ai-entry-insights' ) }
						</Button>
					</div>
					<ErrorNotice error={ testError } />
				</Card>
				<Card title={ __( 'Result', 'dev3-ai-entry-insights' ) }>
					{ result ? (
						<div className="dev3ei-testresult">
							<div className="dev3ei-chips">
								<ScorePill score={ result.lead_score } hot={ form.hot_threshold } />
								<SentimentBadge value={ result.sentiment } />
								<UrgencyBadge value={ result.urgency } />
								<span className="dev3ei-muted">{ CATEGORIES[ result.category ] || result.category }</span>
							</div>
							<p className="dev3ei-muted">{ result.lead_reason }</p>
							{ result.tags.length ? <Tags tags={ result.tags } /> : <p className="dev3ei-muted">{ __( 'No tag rule matched.', 'dev3-ai-entry-insights' ) }</p> }
						</div>
					) : (
						<p className="dev3ei-muted">{ /* translators: %d: hot threshold */ sprintf( __( 'Run a test to see the score. Hot leads start at %d.', 'dev3-ai-entry-insights' ), form.hot_threshold ) }</p>
					) }
				</Card>
			</Columns>
		</form>
	);
}
