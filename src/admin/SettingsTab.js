import { __ } from '@wordpress/i18n';
import config from '../core3/config';
import { Button, Card, FormGrid, Notice, NumberField, PageHeader, Stack, TextField, Toggle } from '../core3/ui';
import { useSettingsForm } from './helpers';

const KEYS = [ 'auto_analyze', 'alerts_enabled', 'alert_emails', 'alert_hot', 'alert_urgent', 'alert_negative', 'store_page_url', 'retention_days', 'delete_on_uninstall' ];

/**
 * Automation, alerts and privacy.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.save     Save.
 * @return {Element} Screen.
 */
export default function SettingsTab( { settings, save } ) {
	const { form, set, dirty, busy, submit, reset } = useSettingsForm( settings, save, KEYS );
	const footer = (
		<>
			<Button variant="ghost" onClick={ reset } disabled={ ! dirty || busy }>
				{ __( 'Reset', 'dev3-ai-entry-insights' ) }
			</Button>
			<Button variant="primary" type="submit" loading={ busy } disabled={ ! dirty }>
				{ __( 'Save settings', 'dev3-ai-entry-insights' ) }
			</Button>
		</>
	);
	return (
		<form className="dev3ei-form" onSubmit={ submit }>
			<PageHeader title={ __( 'Settings', 'dev3-ai-entry-insights' ) } description={ __( 'Automation, alerts and data handling.', 'dev3-ai-entry-insights' ) } />
			<Card title={ __( 'Automation', 'dev3-ai-entry-insights' ) } footer={ footer }>
				<Toggle
					label={ __( 'Analyze new entries with DEV3 AI automatically', 'dev3-ai-entry-insights' ) }
					checked={ form.auto_analyze }
					onChange={ set( 'auto_analyze' ) }
					help={ __( 'Runs in the background a few seconds after each submission, so forms never get slower. Turn it off per form on the Forms screen.', 'dev3-ai-entry-insights' ) }
				/>
				{ ! settings.api_key_set && <Notice tone="info">{ __( 'Without an API key, entries get rule-based insights only.', 'dev3-ai-entry-insights' ) }</Notice> }
			</Card>
			<Card title={ __( 'Email alerts', 'dev3-ai-entry-insights' ) } description={ __( 'Get an email when an entry needs you. Sent once per entry, after the analysis.', 'dev3-ai-entry-insights' ) } footer={ footer }>
				<Stack gap={ 12 }>
					<Toggle label={ __( 'Send alerts', 'dev3-ai-entry-insights' ) } checked={ form.alerts_enabled } onChange={ set( 'alerts_enabled' ) } />
					<TextField label={ __( 'Send to', 'dev3-ai-entry-insights' ) } value={ form.alert_emails } onChange={ set( 'alert_emails' ) } placeholder={ config.adminEmail || 'sales@example.com' } help={ __( 'Comma-separated. Empty = the site admin email.', 'dev3-ai-entry-insights' ) } />
					<Toggle label={ __( 'Hot leads (score at or above the threshold in Rules)', 'dev3-ai-entry-insights' ) } checked={ form.alert_hot } onChange={ set( 'alert_hot' ) } />
					<Toggle label={ __( 'High urgency', 'dev3-ai-entry-insights' ) } checked={ form.alert_urgent } onChange={ set( 'alert_urgent' ) } />
					<Toggle label={ __( 'Negative sentiment', 'dev3-ai-entry-insights' ) } checked={ form.alert_negative } onChange={ set( 'alert_negative' ) } />
				</Stack>
			</Card>
			<Card title={ __( 'Privacy & data', 'dev3-ai-entry-insights' ) } footer={ footer }>
				<Notice tone="info">
					{ __( 'Only text answers are sent to Google Gemini. Phone, password, hidden, file, signature, payment and consent fields are never sent, emails are reduced to their domain and names to the first name. Personal data export and erase requests (Tools → Export/Erase Personal Data) include captured entries.', 'dev3-ai-entry-insights' ) }
				</Notice>
				<FormGrid>
					<NumberField label={ __( 'Keep entries for (days)', 'dev3-ai-entry-insights' ) } value={ form.retention_days } min={ 0 } max={ 3650 } onChange={ set( 'retention_days' ) } help={ __( '0 keeps them forever. Older entries are deleted daily.', 'dev3-ai-entry-insights' ) } />
				</FormGrid>
				<Toggle label={ __( 'Store the page URL the form was sent from', 'dev3-ai-entry-insights' ) } checked={ form.store_page_url } onChange={ set( 'store_page_url' ) } />
				<Toggle label={ __( 'Delete all plugin data when the plugin is deleted', 'dev3-ai-entry-insights' ) } checked={ !! form.delete_on_uninstall } onChange={ set( 'delete_on_uninstall' ) } />
			</Card>
		</form>
	);
}
