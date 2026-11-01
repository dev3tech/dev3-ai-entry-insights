import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Badge, useToast } from '../core3/ui';

export const SOURCES = {
	gravityforms: __( 'Gravity Forms', 'dev3-ai-entry-insights' ),
	wpforms: __( 'WPForms', 'dev3-ai-entry-insights' ),
	cf7: __( 'Contact Form 7', 'dev3-ai-entry-insights' ),
};

export const CATEGORIES = {
	sales_lead: __( 'Sales lead', 'dev3-ai-entry-insights' ),
	quote_request: __( 'Quote request', 'dev3-ai-entry-insights' ),
	support: __( 'Support', 'dev3-ai-entry-insights' ),
	feedback: __( 'Feedback', 'dev3-ai-entry-insights' ),
	complaint: __( 'Complaint', 'dev3-ai-entry-insights' ),
	booking: __( 'Booking', 'dev3-ai-entry-insights' ),
	job_application: __( 'Job application', 'dev3-ai-entry-insights' ),
	partnership: __( 'Partnership', 'dev3-ai-entry-insights' ),
	newsletter: __( 'Newsletter', 'dev3-ai-entry-insights' ),
	spam: __( 'Spam', 'dev3-ai-entry-insights' ),
	other: __( 'Other', 'dev3-ai-entry-insights' ),
};

export const SENTIMENT = {
	positive: { label: __( 'Positive', 'dev3-ai-entry-insights' ), tone: 'success' },
	neutral: { label: __( 'Neutral', 'dev3-ai-entry-insights' ), tone: 'neutral' },
	negative: { label: __( 'Negative', 'dev3-ai-entry-insights' ), tone: 'danger' },
};

export const URGENCY = {
	high: { label: __( 'High', 'dev3-ai-entry-insights' ), tone: 'danger' },
	medium: { label: __( 'Medium', 'dev3-ai-entry-insights' ), tone: 'warning' },
	low: { label: __( 'Low', 'dev3-ai-entry-insights' ), tone: 'neutral' },
};

export const STATUS = {
	new: { label: __( 'New', 'dev3-ai-entry-insights' ), tone: 'brand' },
	read: { label: __( 'Read', 'dev3-ai-entry-insights' ), tone: 'neutral' },
	replied: { label: __( 'Replied', 'dev3-ai-entry-insights' ), tone: 'success' },
	archived: { label: __( 'Archived', 'dev3-ai-entry-insights' ), tone: 'neutral' },
};

export const HISTORY = {
	captured: __( 'Captured', 'dev3-ai-entry-insights' ),
	imported: __( 'Imported', 'dev3-ai-entry-insights' ),
	analyzed: __( 'Analysed by DEV3 AI', 'dev3-ai-entry-insights' ),
	ai_failed: __( 'DEV3 AI analysis failed', 'dev3-ai-entry-insights' ),
	alerted: __( 'Alert sent', 'dev3-ai-entry-insights' ),
	replied: __( 'Reply sent', 'dev3-ai-entry-insights' ),
	reply_failed: __( 'Reply failed', 'dev3-ai-entry-insights' ),
	status: __( 'Status changed', 'dev3-ai-entry-insights' ),
	note: __( 'Note', 'dev3-ai-entry-insights' ),
	tags_accepted: __( 'AI tags accepted', 'dev3-ai-entry-insights' ),
	tags_rejected: __( 'AI tags dismissed', 'dev3-ai-entry-insights' ),
	gf_note: __( 'Gravity Forms note added', 'dev3-ai-entry-insights' ),
};

export const options = ( map, all ) =>
	( all ? [ { value: '', label: all } ] : [] ).concat( Object.keys( map ).map( ( value ) => ( { value, label: typeof map[ value ] === 'string' ? map[ value ] : map[ value ].label } ) ) );

/**
 * Who did a history step.
 *
 * @param {string} by rules|ai|system|user:<id>.
 * @return {string} Label.
 */
export function byLabel( by ) {
	if ( by === 'ai' ) {
		return __( 'DEV3 AI', 'dev3-ai-entry-insights' );
	}
	if ( by === 'system' ) {
		return __( 'automatic', 'dev3-ai-entry-insights' );
	}
	if ( String( by ).startsWith( 'user:' ) ) {
		return __( 'a team member', 'dev3-ai-entry-insights' );
	}
	return by || '';
}

/**
 * Parses a MySQL UTC or ISO date.
 *
 * @param {string} value Date.
 * @return {Date} Date.
 */
export const toDate = ( value ) => new Date( String( value ).replace( ' ', 'T' ) + ( /Z|[+-]\d\d:?\d\d$/.test( value ) ? '' : 'Z' ) );

/**
 * Relative time ("3h ago").
 *
 * @param {string} iso Date.
 * @return {string} Label.
 */
export function ago( iso ) {
	if ( ! iso ) {
		return '';
	}
	const d = toDate( iso );
	const s = Math.max( 0, ( Date.now() - d.getTime() ) / 1000 );
	if ( isNaN( s ) ) {
		return '';
	}
	if ( s < 60 ) {
		return __( 'just now', 'dev3-ai-entry-insights' );
	}
	if ( s < 3600 ) {
		/* translators: %d: minutes */
		return sprintf( __( '%dm ago', 'dev3-ai-entry-insights' ), Math.floor( s / 60 ) );
	}
	if ( s < 86400 ) {
		/* translators: %d: hours */
		return sprintf( __( '%dh ago', 'dev3-ai-entry-insights' ), Math.floor( s / 3600 ) );
	}
	if ( s < 86400 * 30 ) {
		/* translators: %d: days */
		return sprintf( __( '%dd ago', 'dev3-ai-entry-insights' ), Math.floor( s / 86400 ) );
	}
	return d.toLocaleDateString( undefined, { year: 'numeric', month: 'short', day: 'numeric' } );
}

/**
 * Score tone.
 *
 * @param {number} score Score.
 * @param {number} hot   Hot threshold.
 * @return {string} Tone.
 */
export const scoreTone = ( score, hot = 70 ) => {
	if ( score >= hot ) {
		return 'success';
	}
	if ( score >= 40 ) {
		return 'warning';
	}
	return 'neutral';
};

/**
 * Lead score pill.
 *
 * @param {Object} props       Props.
 * @param {number} props.score Score.
 * @param {number} props.hot   Hot threshold.
 * @return {Element} Pill.
 */
export function ScorePill( { score, hot = 70 } ) {
	const tone = scoreTone( score, hot );
	/* translators: %d: lead score */
	const label = sprintf( __( 'Lead score %d out of 100', 'dev3-ai-entry-insights' ), score );
	return (
		<span className={ `dev3ei-score dev3ei-score--${ tone }` } title={ label }>
			<span className="dev3ei-ui-sr-only">{ label }</span>
			<span aria-hidden="true">{ score }</span>
		</span>
	);
}

/**
 * Sentiment badge.
 *
 * @param {Object} props       Props.
 * @param {string} props.value Sentiment.
 * @return {Element} Badge.
 */
export function SentimentBadge( { value } ) {
	const s = SENTIMENT[ value ] || SENTIMENT.neutral;
	return (
		<Badge tone={ s.tone } dot>
			{ s.label }
		</Badge>
	);
}

/**
 * Urgency badge.
 *
 * @param {Object} props       Props.
 * @param {string} props.value Urgency.
 * @return {Element} Badge.
 */
export function UrgencyBadge( { value } ) {
	const u = URGENCY[ value ] || URGENCY.low;
	/* translators: %s: urgency level */
	return <Badge tone={ u.tone }>{ sprintf( __( '%s urgency', 'dev3-ai-entry-insights' ), u.label ) }</Badge>;
}

/**
 * Tag chips.
 *
 * @param {Object}   props          Props.
 * @param {string[]} props.tags     Tags.
 * @param {number}   props.max      Max shown.
 * @param {Function} props.onRemove Remove handler (shows × buttons).
 * @return {Element|null} Chips.
 */
export function Tags( { tags = [], max = 0, onRemove } ) {
	if ( ! tags.length ) {
		return null;
	}
	const shown = max ? tags.slice( 0, max ) : tags;
	return (
		<span className="dev3ei-tags">
			{ shown.map( ( t ) => (
				<span key={ t } className="dev3ei-tag">
					#{ t }
					{ onRemove && (
						<button
							type="button"
							className="dev3ei-tag__x"
							onClick={ () => onRemove( t ) }
							/* translators: %s: tag */
							aria-label={ sprintf( __( 'Remove tag %s', 'dev3-ai-entry-insights' ), t ) }
						>
							×
						</button>
					) }
				</span>
			) ) }
			{ max > 0 && tags.length > max && <span className="dev3ei-tag dev3ei-tag--more">{ `+${ tags.length - max }` }</span> }
		</span>
	);
}

/**
 * Settings form state shared by the settings screens.
 *
 * @param {Object}   settings Settings.
 * @param {Function} save     Save(patch) → Promise.
 * @param {string[]} keys     Keys this screen edits.
 * @return {Object} { form, set, dirty, busy, submit, reset }.
 */
export function useSettingsForm( settings, save, keys ) {
	const toast = useToast();
	const [ form, setForm ] = useState( settings );
	const [ busy, setBusy ] = useState( false );
	const set = ( key ) => ( value ) => setForm( ( f ) => ( { ...f, [ key ]: value } ) );
	const dirty = keys.some( ( k ) => JSON.stringify( form[ k ] ) !== JSON.stringify( settings[ k ] ) );
	const submit = ( e ) => {
		if ( e && e.preventDefault ) {
			e.preventDefault();
		}
		const patch = {};
		keys.forEach( ( k ) => {
			patch[ k ] = form[ k ] === '' && typeof settings[ k ] === 'number' ? settings[ k ] : form[ k ];
		} );
		setBusy( true );
		return save( patch )
			.then( ( next ) => {
				setForm( next );
				toast.success( __( 'Settings saved.', 'dev3-ai-entry-insights' ) );
				return next;
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};
	return { form, set, setForm, dirty, busy, submit, reset: () => setForm( settings ) };
}
