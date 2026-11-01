import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatNumber } from '../core3/ui';

export const SERIES = [
	{ key: 'positive', label: __( 'Positive', 'dev3-ai-entry-insights' ) },
	{ key: 'neutral', label: __( 'Neutral', 'dev3-ai-entry-insights' ) },
	{ key: 'negative', label: __( 'Negative', 'dev3-ai-entry-insights' ) },
];

/**
 * Short day label.
 *
 * @param {string} date Y-m-d.
 * @return {string} Label.
 */
export const dayLabel = ( date ) => {
	const d = new Date( `${ date }T12:00:00Z` );
	return isNaN( d ) ? date : d.toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
};

/**
 * Daily stacked bars by sentiment, with a focusable tooltip and a screen-reader table.
 *
 * @param {Object} props      Props.
 * @param {Array}  props.data [ { date, positive, neutral, negative } ].
 * @return {Element} Chart.
 */
export function SentimentBars( { data = [] } ) {
	const [ hover, setHover ] = useState( null );
	const totals = data.map( ( d ) => d.positive + d.neutral + d.negative );
	const max = Math.max( 1, ...totals );
	const nice = max <= 4 ? 4 : Math.ceil( max / 4 ) * 4;
	const active = hover !== null ? data[ hover ] : null;
	const dense = data.length > 45;
	return (
		<div className="dev3ei-chart">
			<div className="dev3ei-chart__plot" onMouseLeave={ () => setHover( null ) }>
				<div className="dev3ei-chart__grid" aria-hidden="true">
					<span>{ formatNumber( nice ) }</span>
					<span>{ formatNumber( nice / 2 ) }</span>
					<span>0</span>
				</div>
				<div className={ 'dev3ei-chart__cols' + ( dense ? ' is-dense' : '' ) }>
					{ data.map( ( d, i ) => (
						<button
							type="button"
							key={ d.date }
							className={ 'dev3ei-chart__col' + ( hover === i ? ' is-active' : '' ) }
							onMouseEnter={ () => setHover( i ) }
							onFocus={ () => setHover( i ) }
							onBlur={ () => setHover( null ) }
							aria-label={ `${ dayLabel( d.date ) }: ${ SERIES.map( ( s ) => `${ d[ s.key ] } ${ s.label }` ).join( ', ' ) }` }
						>
							<span className="dev3ei-chart__stack" style={ { height: `${ ( totals[ i ] / nice ) * 100 }%` } }>
								{ SERIES.map( ( s ) => ( d[ s.key ] ? <span key={ s.key } className={ `dev3ei-chart__seg dev3ei-chart__seg--${ s.key }` } style={ { flexGrow: d[ s.key ] } } /> : null ) ) }
							</span>
						</button>
					) ) }
				</div>
				{ active && (
					<div className="dev3ei-chart__tip" role="status" style={ { left: `${ Math.min( 85, Math.max( 15, ( ( hover + 0.5 ) / data.length ) * 100 ) ) }%` } }>
						<strong>{ dayLabel( active.date ) }</strong>
						{ SERIES.map( ( s ) => (
							<span key={ s.key }>
								<i className={ `dev3ei-chart__key dev3ei-chart__key--${ s.key }` } /> { s.label }: { active[ s.key ] }
							</span>
						) ) }
					</div>
				) }
			</div>
			<div className="dev3ei-chart__axis" aria-hidden="true">
				<span>{ data.length ? dayLabel( data[ 0 ].date ) : '' }</span>
				<span>{ data.length ? dayLabel( data[ data.length - 1 ].date ) : '' }</span>
			</div>
			<div className="dev3ei-chart__legend" aria-hidden="true">
				{ SERIES.map( ( s ) => (
					<span key={ s.key }>
						<i className={ `dev3ei-chart__key dev3ei-chart__key--${ s.key }` } /> { s.label }
					</span>
				) ) }
			</div>
			<table className="dev3ei-ui-sr-only">
				<caption>{ __( 'Entries per day by sentiment', 'dev3-ai-entry-insights' ) }</caption>
				<thead>
					<tr>
						<th scope="col">{ __( 'Day', 'dev3-ai-entry-insights' ) }</th>
						{ SERIES.map( ( s ) => (
							<th scope="col" key={ s.key }>
								{ s.label }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ data.map( ( d ) => (
						<tr key={ d.date }>
							<td>{ dayLabel( d.date ) }</td>
							{ SERIES.map( ( s ) => (
								<td key={ s.key }>{ d[ s.key ] }</td>
							) ) }
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

/**
 * Horizontal bars.
 *
 * @param {Object}   props         Props.
 * @param {Array}    props.items   [ { key, label, value } ].
 * @param {string}   props.tone    brand | success | warning | danger.
 * @param {Function} props.onPick  Click on a row (filters).
 * @return {Element} Bars.
 */
export function HBars( { items = [], tone = 'brand', onPick } ) {
	const max = Math.max( 1, ...items.map( ( i ) => i.value ) );
	return (
		<ul className="dev3ei-hbars">
			{ items.map( ( item ) => {
				const body = (
					<>
						<span className="dev3ei-hbars__label">{ item.label }</span>
						<span className="dev3ei-hbars__track" aria-hidden="true">
							<span className={ `dev3ei-hbars__fill dev3ei-hbars__fill--${ item.tone || tone }` } style={ { width: `${ Math.max( 2, ( item.value / max ) * 100 ) }%` } } />
						</span>
						<span className="dev3ei-hbars__value">{ formatNumber( item.value ) }</span>
					</>
				);
				return (
					<li key={ item.key }>
						{ onPick ? (
							<button type="button" className="dev3ei-hbars__row" onClick={ () => onPick( item.key ) }>
								{ body }
							</button>
						) : (
							<span className="dev3ei-hbars__row">{ body }</span>
						) }
					</li>
				);
			} ) }
		</ul>
	);
}

/**
 * Columns histogram (lead-score distribution).
 *
 * @param {Object} props      Props.
 * @param {Array}  props.data [ { label, count } ].
 * @param {number} props.hotFrom Index from which bars are "hot".
 * @return {Element} Histogram.
 */
export function Histogram( { data = [], hotFrom = 3 } ) {
	const max = Math.max( 1, ...data.map( ( d ) => d.count ) );
	return (
		<div className="dev3ei-histo">
			<ul className="dev3ei-histo__cols">
				{ data.map( ( d, i ) => (
					<li key={ d.label } className={ i >= hotFrom ? 'is-hot' : '' }>
						<span className="dev3ei-histo__value">{ formatNumber( d.count ) }</span>
						<span className="dev3ei-histo__bar" style={ { height: `${ Math.max( 3, ( d.count / max ) * 100 ) }%` } } aria-hidden="true" />
						<span className="dev3ei-histo__label">{ d.label }</span>
					</li>
				) ) }
			</ul>
		</div>
	);
}

/**
 * Positive / neutral / negative bar.
 *
 * @param {Object} props          Props.
 * @param {number} props.positive Count.
 * @param {number} props.neutral  Count.
 * @param {number} props.negative Count.
 * @return {Element} Bar.
 */
export function MoodBar( { positive = 0, neutral = 0, negative = 0 } ) {
	const total = positive + neutral + negative;
	const pct = ( n ) => ( total ? ( n / total ) * 100 : 0 );
	const label = `${ positive } ${ __( 'positive', 'dev3-ai-entry-insights' ) }, ${ neutral } ${ __( 'neutral', 'dev3-ai-entry-insights' ) }, ${ negative } ${ __( 'negative', 'dev3-ai-entry-insights' ) }`;
	return (
		<span className="dev3ei-moodbar" role="img" aria-label={ label } title={ label }>
			<span className="dev3ei-moodbar__seg dev3ei-moodbar__seg--pos" style={ { width: `${ pct( positive ) }%` } } />
			<span className="dev3ei-moodbar__seg dev3ei-moodbar__seg--neu" style={ { width: `${ pct( neutral ) }%` } } />
			<span className="dev3ei-moodbar__seg dev3ei-moodbar__seg--neg" style={ { width: `${ pct( negative ) }%` } } />
		</span>
	);
}
