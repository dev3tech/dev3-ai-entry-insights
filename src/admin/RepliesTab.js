import { __ } from '@wordpress/i18n';
import config from '../core3/config';
import { Button, Card, FormGrid, IconButton, PageHeader, Select, TextField, Textarea } from '../core3/ui';
import { useSettingsForm } from './helpers';

const KEYS = [ 'voice_tone', 'voice_guidelines', 'reply_signature', 'reply_from_name', 'reply_from_email', 'templates' ];

const TONES = [
	{ value: 'friendly', label: __( 'Friendly', 'dev3-ai-entry-insights' ) },
	{ value: 'professional', label: __( 'Professional', 'dev3-ai-entry-insights' ) },
	{ value: 'concise', label: __( 'Concise', 'dev3-ai-entry-insights' ) },
	{ value: 'apologetic', label: __( 'Apologetic', 'dev3-ai-entry-insights' ) },
	{ value: 'enthusiastic', label: __( 'Enthusiastic', 'dev3-ai-entry-insights' ) },
];

/**
 * Brand voice, sender and canned reply templates.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.save     Save.
 * @return {Element} Screen.
 */
export default function RepliesTab( { settings, save } ) {
	const { form, set, dirty, busy, submit, reset } = useSettingsForm( settings, save, KEYS );
	const templates = form.templates || [];
	const setTpl = ( i, patch ) => set( 'templates' )( templates.map( ( t, j ) => ( j === i ? { ...t, ...patch } : t ) ) );
	const footer = (
		<>
			<Button variant="ghost" onClick={ reset } disabled={ ! dirty || busy }>
				{ __( 'Reset', 'dev3-ai-entry-insights' ) }
			</Button>
			<Button variant="primary" type="submit" loading={ busy } disabled={ ! dirty }>
				{ __( 'Save', 'dev3-ai-entry-insights' ) }
			</Button>
		</>
	);
	return (
		<form className="dev3ei-form" onSubmit={ submit }>
			<PageHeader title={ __( 'Replies', 'dev3-ai-entry-insights' ) } description={ __( 'How replies sound and who they come from. Every reply is reviewed and sent by a person.', 'dev3-ai-entry-insights' ) } />
			<Card title={ __( 'Brand voice for DEV3 AI drafts', 'dev3-ai-entry-insights' ) } footer={ footer }>
				<FormGrid>
					<Select label={ __( 'Default tone', 'dev3-ai-entry-insights' ) } value={ form.voice_tone } onChange={ set( 'voice_tone' ) } options={ TONES } />
					<TextField label={ __( 'Sign-off', 'dev3-ai-entry-insights' ) } value={ form.reply_signature } onChange={ set( 'reply_signature' ) } placeholder={ __( 'Anna from the Northwind team', 'dev3-ai-entry-insights' ) } />
				</FormGrid>
				<Textarea
					label={ __( 'Facts and guidelines', 'dev3-ai-entry-insights' ) }
					value={ form.voice_guidelines }
					onChange={ set( 'voice_guidelines' ) }
					rows={ 5 }
					maxLength={ 2000 }
					help={ __( 'Opening hours, prices you are happy to quote, what to avoid promising. DEV3 AI never invents facts that are not here or in the notes.', 'dev3-ai-entry-insights' ) }
				/>
			</Card>
			<Card title={ __( 'Sender', 'dev3-ai-entry-insights' ) } description={ __( 'Replies are sent as plain-text emails with wp_mail. Leave empty to use your site\'s default sender.', 'dev3-ai-entry-insights' ) } footer={ footer }>
				<FormGrid>
					<TextField label={ __( 'From name', 'dev3-ai-entry-insights' ) } value={ form.reply_from_name } onChange={ set( 'reply_from_name' ) } placeholder={ config.siteName || '' } />
					<TextField label={ __( 'From email', 'dev3-ai-entry-insights' ) } type="email" value={ form.reply_from_email } onChange={ set( 'reply_from_email' ) } placeholder="hello@example.com" />
				</FormGrid>
			</Card>
			<Card title={ __( 'Templates', 'dev3-ai-entry-insights' ) } description={ __( 'Canned replies that work without AI. Placeholders: {name}, {first_name}, {form}, {site}, {summary}, {email}.', 'dev3-ai-entry-insights' ) } footer={ footer }>
				<ul className="dev3ei-templates">
					{ templates.map( ( t, i ) => (
						<li key={ t.id || i } className="dev3ei-template">
							<div className="dev3ei-template__head">
								<TextField label={ __( 'Template name', 'dev3-ai-entry-insights' ) } value={ t.name } onChange={ ( v ) => setTpl( i, { name: v } ) } />
								<IconButton icon="trash" label={ __( 'Remove template', 'dev3-ai-entry-insights' ) } onClick={ () => set( 'templates' )( templates.filter( ( x, j ) => j !== i ) ) } />
							</div>
							<TextField label={ __( 'Subject', 'dev3-ai-entry-insights' ) } value={ t.subject } onChange={ ( v ) => setTpl( i, { subject: v } ) } />
							<Textarea label={ __( 'Message', 'dev3-ai-entry-insights' ) } value={ t.body } onChange={ ( v ) => setTpl( i, { body: v } ) } rows={ 5 } />
						</li>
					) ) }
				</ul>
				<div>
					<Button size="sm" variant="secondary" icon="plus" onClick={ () => set( 'templates' )( [ ...templates, { id: `tpl${ Date.now() }`, name: __( 'New template', 'dev3-ai-entry-insights' ), subject: '', body: 'Hi {first_name},\n\n' } ] ) }>
						{ __( 'Add template', 'dev3-ai-entry-insights' ) }
					</Button>
				</div>
			</Card>
		</form>
	);
}
