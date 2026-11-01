/**
 * Admin screens: dashboard + digest draft, entries inbox + bulk, entry drawer (insights, tags, AI reply review),
 * forms, rules preview, replies, settings, router and helpers.
 */
import apiFetch from '@wordpress/api-fetch';
import { ToastProvider } from '../../src/core3/ui';
import Dashboard from '../../src/admin/Dashboard';
import Entries from '../../src/admin/Entries';
import EntryDrawer from '../../src/admin/EntryDrawer';
import Forms from '../../src/admin/Forms';
import RulesTab from '../../src/admin/RulesTab';
import RepliesTab from '../../src/admin/RepliesTab';
import SettingsTab from '../../src/admin/SettingsTab';
import { parseHash } from '../../src/admin/App';
import { ago, scoreTone, options, SENTIMENT } from '../../src/admin/helpers';
import { act, change, click, render } from './render';

const wait = ( ms ) => act( () => new Promise( ( r ) => setTimeout( r, ms ) ) );

Object.assign( window.dev3eiConfig, {
	canGenerate: true,
	exportUrl: '/wp-admin/admin-post.php?action=dev3ei_export&_wpnonce=abc',
	adapters: [
		{ id: 'gravityforms', label: 'Gravity Forms', active: true, enabled: true, version: '2.9.0', can_import: true, import_note: '' },
		{ id: 'wpforms', label: 'WPForms', active: true, enabled: true, version: '1.9 Lite', can_import: false, import_note: 'WPForms Lite does not store entries.' },
		{ id: 'cf7', label: 'Contact Form 7', active: false, enabled: true, version: '', can_import: false, import_note: '', plugin_url: 'https://wordpress.org/plugins/contact-form-7/' },
	],
	adminEmail: 'admin@example.org',
} );

const SETTINGS = {
	api_key_set: true,
	ai_enabled: true,
	hot_threshold: 70,
	score_mode: 'ai',
	score_base: 20,
	signals: { phone: 15, company_email: 15, company: 10, budget: 15, buying: 15, urgent: 5, long_message: 5, negative: -10 },
	keyword_rules: [ { keyword: 'enterprise', points: 20 } ],
	tag_rules: [ { when: 'score', value: '70', tag: 'hot-lead' } ],
	auto_apply_ai_tags: false,
	voice_tone: 'friendly',
	voice_guidelines: '',
	reply_signature: '',
	reply_from_name: '',
	reply_from_email: '',
	templates: [ { id: 'thanks', name: 'Thanks', subject: 'Thanks {first_name}', body: 'Hi {first_name}' } ],
	auto_analyze: true,
	alerts_enabled: false,
	alert_emails: '',
	alert_hot: true,
	alert_urgent: true,
	alert_negative: false,
	store_page_url: true,
	retention_days: 365,
};

const ENTRY = {
	id: 7,
	source: 'gravityforms',
	form_id: 1,
	form_key: 'gravityforms:1',
	form_title: 'Request a quote',
	name: 'Anna Berg',
	email: 'anna@northwind.com',
	excerpt: 'We need a new website',
	summary: 'Wants a quote for a 12-page website with booking by June.',
	sentiment: 'positive',
	intent: 'Quote for a website',
	category: 'quote_request',
	urgency: 'medium',
	lead_score: 86,
	rule_score: 70,
	lead_reason: 'Company email, clear budget and timeline.',
	language: 'en',
	tags: [ 'website' ],
	suggested_tags: [ 'booking', 'redesign' ],
	insight_src: 'ai',
	ai_state: 'done',
	ai_error: '',
	status: 'new',
	starred: false,
	created_at: '2026-10-24 09:00:00',
	replied_at: null,
	fields: [
		{ key: 'f1', label: 'Name', type: 'name', value: 'Anna Berg' },
		{ key: 'f2', label: 'Email', type: 'email', value: 'anna@northwind.com' },
		{ key: 'f3', label: 'Message', type: 'textarea', value: 'We need a new website' },
	],
	history: [ { event: 'captured', detail: 'gravityforms', by: 'system', at: '2026-10-24T09:00:00Z' } ],
	note: '',
	page_url: 'https://example.org/contact/',
	model: 'gemini-2.5-flash',
	entry_url: '/wp-admin/admin.php?page=gf_entries&view=entry&id=1&lid=3',
	can_gf_note: true,
};

const STATS = {
	days: 30,
	total: 42,
	prev_total: 30,
	avg_score: 51,
	prev_avg: 45,
	hot: 9,
	hot_threshold: 70,
	negative_pct: 12,
	reply_rate: 33,
	ai_analyzed: 40,
	pending: 0,
	attention: [ { id: 7, name: 'Anna Berg', form_title: 'Request a quote', summary: 'Wants a quote fast', intent: '', sentiment: 'negative', urgency: 'high', lead_score: 88, created_at: '2026-10-24 09:00:00' } ],
	attention_total: 1,
	sentiment: { positive: 20, neutral: 17, negative: 5 },
	series: [
		{ date: '2026-10-23', positive: 2, neutral: 1, negative: 0 },
		{ date: '2026-10-24', positive: 1, neutral: 2, negative: 1 },
	],
	scores: [
		{ label: '0–19', count: 3 },
		{ label: '20–39', count: 10 },
		{ label: '40–59', count: 12 },
		{ label: '60–79', count: 9 },
		{ label: '80–100', count: 8 },
	],
	categories: { quote_request: 14, support: 9, complaint: 3 },
	urgency: { low: 30, medium: 8, high: 4 },
	intents: [ { label: 'Quote for a website', count: 6 } ],
	tags: { 'hot-lead': 9, website: 6 },
	forms: [ { key: 'gravityforms:1', source: 'gravityforms', title: 'Request a quote', total: 30, avg_score: 60, hot: 8, positive: 15, neutral: 12, negative: 3 } ],
	sources: { gravityforms: 30, wpforms: 12 },
	digest: null,
};

/**
 * Routes apiFetch calls by URL substring.
 *
 * @param {Object} routes "METHOD path" or "path" => response (or fn(options)).
 */
function routeApi( routes ) {
	apiFetch.mockImplementation( ( opts ) => {
		const url = opts.url || '';
		const method = ( opts.method || 'GET' ).toUpperCase();
		const keys = Object.keys( routes ).sort( ( a, b ) => b.length - a.length );
		for ( const k of keys ) {
			const [ m, p ] = k.includes( ' ' ) ? k.split( ' ' ) : [ null, k ];
			if ( ( ! m || m === method ) && url.includes( p ) ) {
				const r = routes[ k ];
				return Promise.resolve( typeof r === 'function' ? r( opts ) : r );
			}
		}
		return new Promise( () => {} );
	} );
}

const wrap = ( el ) => <ToastProvider>{ el }</ToastProvider>;

beforeEach( () => {
	apiFetch.mockReset();
	document.body.innerHTML = '';
	window.location.hash = '';
} );

describe( 'helpers + router', () => {
	test( 'scoreTone and options', () => {
		expect( scoreTone( 80, 70 ) ).toBe( 'success' );
		expect( scoreTone( 50, 70 ) ).toBe( 'warning' );
		expect( scoreTone( 10, 70 ) ).toBe( 'neutral' );
		expect( options( SENTIMENT, 'Any' )[ 0 ] ).toEqual( { value: '', label: 'Any' } );
		expect( options( SENTIMENT ).map( ( o ) => o.value ) ).toEqual( [ 'positive', 'neutral', 'negative' ] );
	} );

	test( 'ago formats relative times', () => {
		const iso = new Date( Date.now() - 2 * 3600 * 1000 ).toISOString();
		expect( ago( iso ) ).toBe( '2h ago' );
		expect( ago( '' ) ).toBe( '' );
	} );

	test( 'parseHash reads id, params and query', () => {
		window.location.hash = '#/entries/12?tag=hot-lead&view=hot';
		expect( parseHash() ).toMatchObject( { id: 'entries', params: [ '12' ], query: { tag: 'hot-lead', view: 'hot' } } );
		window.location.hash = '#/nope';
		expect( parseHash().id ).toBe( 'insights' );
	} );
} );

describe( 'Dashboard', () => {
	test( 'renders KPIs, charts, attention list and opens entries', async () => {
		routeApi( { stats: STATS, forms: { forms: [], adapters: [] } } );
		const navigate = jest.fn();
		const view = await render( wrap( <Dashboard settings={ SETTINGS } navigate={ navigate } onUsage={ () => {} } /> ) );
		await view.flush();
		expect( view.getByText( 'Hot leads' ) ).toBeTruthy();
		expect( view.getByText( '42' ) ).toBeTruthy();
		expect( view.getByText( '+40%' ) ).toBeTruthy();
		expect( view.$$( '.dev3ei-chart__col' ) ).toHaveLength( 2 );
		expect( view.$$( '.dev3ei-histo__cols li.is-hot' ) ).toHaveLength( 2 );
		await click( view.getByText( 'Wants a quote fast' ).closest( 'button' ) );
		expect( navigate ).toHaveBeenCalledWith( 'entries/7' );
		await click( view.getByText( 'Quote request' ).closest( 'button' ) );
		expect( navigate ).toHaveBeenCalledWith( 'entries?category=quote_request' );
		view.unmount();
	} );

	test( 'digest is a draft until pinned', async () => {
		const digest = { headline: 'Quote requests doubled', summary: 'More people ask for websites.', themes: [ { label: 'Websites', share: 0.4, sentiment: 0.5 } ], opportunities: [ 'Offer a package' ], risks: [ 'Slow replies' ], actions: [ 'Reply within a day' ] };
		const pin = jest.fn( () => ( { pinned: { digest, days: 30, pinned_at: new Date().toISOString() } } ) );
		routeApi( { stats: STATS, forms: { forms: [] }, 'POST digest/pin': pin, 'POST digest': { digest, days: 30, form: '', model: 'gemini-2.5-flash' }, 'usage/month': {} } );
		const view = await render( wrap( <Dashboard settings={ SETTINGS } navigate={ () => {} } onUsage={ () => {} } /> ) );
		await view.flush();
		await click( view.getByText( 'Summarize trends with DEV3 AI' ).closest( 'button' ) );
		await view.flush();
		expect( view.getByText( 'Not saved yet' ) ).toBeTruthy();
		expect( view.getByText( 'Quote requests doubled' ) ).toBeTruthy();
		expect( pin ).not.toHaveBeenCalled();
		await click( view.getByText( 'Pin to dashboard' ).closest( 'button' ) );
		await view.flush();
		expect( pin ).toHaveBeenCalled();
		expect( view.queryByText( 'Not saved yet' ) ).toBeNull();
		expect( view.getByText( 'Unpin' ) ).toBeTruthy();
		view.unmount();
	} );

	test( 'no key: shows the connect notice and disables the digest', async () => {
		routeApi( { stats: { ...STATS, total: 0, attention: [], forms: [] }, forms: { forms: [] } } );
		const view = await render( wrap( <Dashboard settings={ { ...SETTINGS, api_key_set: false } } navigate={ () => {} } onUsage={ () => {} } /> ) );
		await view.flush();
		expect( view.getByText( 'Connect DEV3 AI for summaries, intent and smarter lead scores' ) ).toBeTruthy();
		expect( view.getByText( 'Summarize trends with DEV3 AI' ).closest( 'button' ).disabled ).toBe( true );
		expect( view.getByText( 'No entries in this period' ) ).toBeTruthy();
		view.unmount();
	} );
} );

describe( 'Entries inbox', () => {
	test( 'lists entries with filters from the URL and runs a bulk action', async () => {
		const calls = [];
		const bulk = jest.fn( () => ( { done: 1 } ) );
		routeApi( {
			'GET entries?': ( o ) => {
				calls.push( o.url );
				return { items: [ ENTRY ], total: 1, counts: { all: 1, new: 1, hot: 1 }, pending: 0 };
			},
			forms: { forms: [ { key: 'gravityforms:1', title: 'Request a quote' } ] },
			'POST entries/bulk': bulk,
		} );
		const view = await render( wrap( <Entries settings={ SETTINGS } navigate={ () => {} } openId={ 0 } query={ { tag: 'website' } } onUsage={ () => {} } /> ) );
		await wait( 350 );
		await view.flush();
		expect( calls.some( ( u ) => u.includes( 'tag=website' ) ) ).toBe( true );
		expect( view.getByText( 'Anna Berg' ) ).toBeTruthy();
		expect( view.$( '.dev3ei-score--success' ).textContent ).toContain( '86' );
		expect( view.$( 'a[href*="dev3ei_export"]' ).getAttribute( 'href' ) ).toContain( 'tag=website' );
		await click( view.$( '.dev3ei-rowcheck input' ) );
		const select = view.$$( 'select' ).find( ( s ) => Array.from( s.options ).some( ( o ) => o.value === 'archive' ) );
		await change( select, 'archive' );
		await click( view.getByText( 'Apply' ).closest( 'button' ) );
		await view.flush();
		expect( bulk ).toHaveBeenCalled();
		expect( bulk.mock.calls[ 0 ][ 0 ].data ).toMatchObject( { ids: [ 7 ], action: 'archive' } );
		view.unmount();
	} );

	test( 'empty inbox shows the empty state', async () => {
		routeApi( { 'GET entries?': { items: [], total: 0, counts: {}, pending: 0 }, forms: { forms: [] } } );
		const view = await render( wrap( <Entries settings={ SETTINGS } navigate={ () => {} } openId={ 0 } query={ {} } onUsage={ () => {} } /> ) );
		await wait( 350 );
		await view.flush();
		expect( view.getByText( 'No entries here' ) ).toBeTruthy();
		view.unmount();
	} );
} );

describe( 'Entry drawer', () => {
	test( 'shows insights, accepts suggested tags and sends a reviewed AI reply', async () => {
		const accept = jest.fn( () => ( { ...ENTRY, tags: [ 'website', 'booking', 'redesign' ], suggested_tags: [] } ) );
		const send = jest.fn( ( o ) => ( { ...ENTRY, status: 'replied', replied_at: '2026-10-25 10:00:00', ...{ _sent: o.data } } ) );
		routeApi( {
			'GET entries/7': ENTRY,
			'POST entries/7/tags/accept': accept,
			'POST entries/7/reply-draft': { subject: 'Your website quote', body: 'Hi Anna, thanks for the details.', to: ENTRY.email, model: 'gemini-2.5-flash', tokens: 300 },
			'POST entries/7/reply': send,
		} );
		const view = await render( wrap( <EntryDrawer id={ 7 } settings={ SETTINGS } onClose={ () => {} } onChanged={ () => {} } onUsage={ () => {} } /> ) );
		await view.flush();
		expect( view.getByText( 'Wants a quote for a 12-page website with booking by June.' ) ).toBeTruthy();
		expect( view.getByText( /By DEV3 AI/ ) ).toBeTruthy();
		expect( view.$( '[role="meter"]' ).getAttribute( 'aria-valuenow' ) ).toBe( '86' );
		await click( view.getByText( 'Accept all' ).closest( 'button' ) );
		await view.flush();
		expect( accept.mock.calls[ 0 ][ 0 ].data.tags ).toEqual( [ 'booking', 'redesign' ] );
		await click( view.getByText( 'Draft reply with DEV3 AI' ).closest( 'button' ) );
		await view.flush();
		expect( view.getByText( 'Not saved yet' ) ).toBeTruthy();
		expect( send ).not.toHaveBeenCalled();
		await click( view.getByText( 'Send to anna@northwind.com' ).closest( 'button' ) );
		await view.flush();
		expect( send ).toHaveBeenCalled();
		expect( send.mock.calls[ 0 ][ 0 ].data ).toMatchObject( { subject: 'Your website quote', body: 'Hi Anna, thanks for the details.' } );
		view.unmount();
	} );

	test( 'template fills the composer without AI', async () => {
		routeApi( { 'GET entries/7': { ...ENTRY, suggested_tags: [] }, 'POST entries/7/template': { subject: 'Thanks Anna', body: 'Hi Anna', to: ENTRY.email } } );
		const view = await render( wrap( <EntryDrawer id={ 7 } settings={ { ...SETTINGS, api_key_set: false } } onClose={ () => {} } onChanged={ () => {} } onUsage={ () => {} } /> ) );
		await view.flush();
		expect( view.getByText( 'Draft reply with DEV3 AI' ).closest( 'button' ).disabled ).toBe( true );
		const select = view.$$( 'select' ).find( ( s ) => Array.from( s.options ).some( ( o ) => o.value === 'thanks' ) );
		await change( select, 'thanks' );
		await click( view.getByText( 'Use' ).closest( 'button' ) );
		await view.flush();
		expect( view.$$( 'input' ).some( ( i ) => i.value === 'Thanks Anna' ) ).toBe( true );
		view.unmount();
	} );
} );

describe( 'Forms', () => {
	test( 'lists adapters and forms, toggles AI per form and starts an import', async () => {
		const config = jest.fn( () => ( { key: 'gravityforms:1', capture: true, analyze: false } ) );
		const importer = jest.fn( () => ( { job: { id: 5, status: 'queued', total: 3, done: 0, failed: 0, percent: 0 } } ) );
		routeApi( {
			'GET forms': {
				adapters: window.dev3eiConfig.adapters,
				forms: [ { key: 'gravityforms:1', source: 'gravityforms', id: 1, title: 'Request a quote', edit_url: '/edit', live: true, capture: true, analyze: true, total: 3, unread: 1, avg_score: 60, hot: 1, pending: 0, last_at: '2026-10-24 09:00:00' } ],
			},
			'POST forms/config': config,
			'POST forms/import': importer,
			'jobs/5': { id: 5, status: 'running', total: 3, done: 1, failed: 0, percent: 33 },
		} );
		const view = await render( wrap( <Forms settings={ SETTINGS } save={ () => Promise.resolve( SETTINGS ) } navigate={ () => {} } onUsage={ () => {} } /> ) );
		await view.flush();
		expect( view.getByText( 'Not active' ) ).toBeTruthy();
		expect( view.getByText( 'WPForms Lite does not store entries.' ) ).toBeTruthy();
		const aiLabel = view.getByText( 'Analyze Request a quote with DEV3 AI' );
		const aiToggle = document.getElementById( aiLabel.getAttribute( 'for' ) );
		expect( aiToggle.getAttribute( 'aria-checked' ) ).toBe( 'true' );
		await click( aiToggle );
		await view.flush();
		expect( config ).toHaveBeenCalled();
		expect( config.mock.calls[ 0 ][ 0 ].data ).toMatchObject( { key: 'gravityforms:1', analyze: false } );
		await click( view.getByText( 'Import' ).closest( 'button' ) );
		await view.flush();
		await click( view.getByText( 'Start import' ).closest( 'button' ) );
		await view.flush();
		expect( importer.mock.calls[ 0 ][ 0 ].data ).toMatchObject( { key: 'gravityforms:1', limit: 200, analyze: false } );
		view.unmount();
	} );
} );

describe( 'Settings screens', () => {
	test( 'rules: edit a keyword, save and preview', async () => {
		const save = jest.fn( ( patch ) => Promise.resolve( { ...SETTINGS, ...patch } ) );
		routeApi( { 'POST rules/preview': { lead_score: 75, sentiment: 'neutral', urgency: 'high', category: 'quote_request', lead_reason: 'Mentions buying intent.', tags: [ 'hot-lead' ] } } );
		const view = await render( wrap( <RulesTab settings={ SETTINGS } save={ save } /> ) );
		const kw = view.$$( 'input' ).find( ( i ) => i.value === 'enterprise' );
		await change( kw, 'agency' );
		await change( view.$( 'textarea' ), 'We need a quote ASAP' );
		await click( view.getByText( 'Save and test' ).closest( 'button' ) );
		await view.flush();
		expect( save ).toHaveBeenCalled();
		expect( save.mock.calls[ 0 ][ 0 ].keyword_rules ).toEqual( [ { keyword: 'agency', points: 20 } ] );
		expect( view.getByText( 'Mentions buying intent.' ) ).toBeTruthy();
		expect( view.queryByText( 'hot-lead' ) ).toBeTruthy();
		view.unmount();
	} );

	test( 'replies: add a template and save', async () => {
		const save = jest.fn( ( patch ) => Promise.resolve( { ...SETTINGS, ...patch } ) );
		const view = await render( wrap( <RepliesTab settings={ SETTINGS } save={ save } /> ) );
		await click( view.getByText( 'Add template' ).closest( 'button' ) );
		await click( view.$$( 'button[type="submit"]' )[ 0 ] );
		await view.flush();
		expect( save.mock.calls[ 0 ][ 0 ].templates ).toHaveLength( 2 );
		view.unmount();
	} );

	test( 'settings: alerts toggle saves', async () => {
		const save = jest.fn( ( patch ) => Promise.resolve( { ...SETTINGS, ...patch } ) );
		const view = await render( wrap( <SettingsTab settings={ SETTINGS } save={ save } /> ) );
		const label = view.getByText( 'Send alerts' );
		await click( document.getElementById( label.getAttribute( 'for' ) ) );
		await click( view.$$( 'button[type="submit"]' )[ 0 ] );
		await view.flush();
		expect( save.mock.calls[ 0 ][ 0 ].alerts_enabled ).toBe( true );
		view.unmount();
	} );
} );
