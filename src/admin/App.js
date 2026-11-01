import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter } from '../core3/ui';
import Dashboard from './Dashboard';
import Entries from './Entries';
import Forms from './Forms';
import RepliesTab from './RepliesTab';
import RulesTab from './RulesTab';
import SettingsTab from './SettingsTab';

const NAV = [
	{ id: 'insights', label: __( 'Insights', 'dev3-ai-entry-insights' ), icon: 'dashboard' },
	{ id: 'entries', label: __( 'Entries', 'dev3-ai-entry-insights' ), icon: 'inbox' },
	{ id: 'forms', label: __( 'Forms', 'dev3-ai-entry-insights' ), icon: 'file' },
	{ id: 'rules', label: __( 'Rules', 'dev3-ai-entry-insights' ), icon: 'bolt' },
	{ id: 'replies', label: __( 'Replies', 'dev3-ai-entry-insights' ), icon: 'edit' },
	{ id: 'settings', label: __( 'Settings', 'dev3-ai-entry-insights' ), icon: 'settings' },
	{ id: 'api', label: __( 'API Config', 'dev3-ai-entry-insights' ), icon: 'key' },
	{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-entry-insights' ), icon: 'chart' },
];

/**
 * Parses "#/entries/12?tag=x" into { id, params, query }.
 *
 * @return {Object} Route.
 */
export function parseHash() {
	const raw = ( typeof window !== 'undefined' ? window.location.hash : '' ).replace( /^#\/?/, '' );
	const [ path, search = '' ] = raw.split( '?' );
	const [ id, ...params ] = path.split( '/' ).filter( Boolean );
	const known = NAV.some( ( n ) => n.id === id );
	const query = {};
	new URLSearchParams( search ).forEach( ( v, k ) => ( query[ k ] = v ) );
	return { id: known ? id : 'insights', params: known ? params : [], query: known ? query : {}, key: raw };
}

/**
 * Hash router with query strings.
 *
 * @return {Array} [ route, navigate ].
 */
function useRoute() {
	const [ route, setRoute ] = useState( parseHash );
	useEffect( () => {
		const onHash = () => setRoute( parseHash() );
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );
	const navigate = useCallback( ( path ) => {
		window.location.hash = `#/${ String( path ).replace( /^#?\/?/, '' ) }`;
	}, [] );
	return [ route, navigate ];
}

export default function App() {
	const [ route, navigate ] = useRoute();
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );
	const [ counts, setCounts ] = useState( null );

	const load = () => {
		setError( null );
		request( 'settings' ).then( setSettings ).catch( setError );
	};
	useEffect( load, [] );
	useEffect( () => {
		request( 'entries?per_page=1' )
			.then( ( res ) => setCounts( res.counts ) )
			.catch( () => {} );
	}, [] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			return next;
		} );
	const onUsage = () => setMeter( ( n ) => n + 1 );

	const screens = {
		insights: () => <Dashboard settings={ settings } navigate={ navigate } onUsage={ onUsage } />,
		entries: () => <Entries settings={ settings } navigate={ navigate } openId={ Number( route.params[ 0 ] ) || 0 } query={ route.query } onUsage={ onUsage } onCounts={ setCounts } />,
		forms: () => <Forms settings={ settings } save={ save } navigate={ navigate } onUsage={ onUsage } />,
		rules: () => <RulesTab settings={ settings } save={ save } />,
		replies: () => <RepliesTab settings={ settings } save={ save } />,
		settings: () => <SettingsTab settings={ settings } save={ save } />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', 'dev3-ai-entry-insights' ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget.', 'dev3-ai-entry-insights' ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', 'dev3-ai-entry-insights' ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', 'dev3-ai-entry-insights' ) } />
				<UsageTab />
			</>
		),
	};

	const nav = NAV.map( ( n ) => ( n.id === 'entries' && counts && counts.new ? { ...n, badge: String( counts.new ) } : n ) );

	return (
		<AppShell
			name={ config.name || 'DEV3 AI Entry Insights for Gravity Forms & WPForms' }
			version={ config.version }
			icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
			nav={ nav }
			active={ route.id }
			onNavigate={ ( id ) => navigate( id ) }
			actions={ <UsageMeter compact refreshKey={ meter } /> }
			footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key.', 'dev3-ai-entry-insights' ) }
		>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! settings && ! error && <SkeletonCard lines={ 5 } /> }
			{ settings && screens[ route.id ]() }
		</AppShell>
	);
}
