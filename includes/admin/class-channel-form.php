<?php
/**
 * The forms to add and edit a channel instance.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

use WPUN\Boolean_Field;
use WPUN\Channel;
use WPUN\Channel_Instance;
use WPUN\Channel_Registry;
use WPUN\Channel_Store;
use WPUN\Dispatcher;
use WPUN\Enum_Field;
use WPUN\Field_Types;
use WPUN\Routing;
use WPUN\Settings;
use WPUN\State;

/**
 * Draws the module picker and the add and edit forms of the Channels tab, and saves the forms.
 *
 * Nothing needs JavaScript. A form that fails validation is stored for the current user, and drawn again with
 * the submitted values (a secret is never kept) and an error on each field at fault.
 *
 * @internal
 */
class Channel_Form {
	const SAVE_ACTION = 'sc_wpun_save_channel';
	const META_KEY    = 'sc_wpun_channel_form';
	const ID_PATTERN  = '/^[a-z0-9]+(?:_[a-z0-9]+)*$/D';

	/**
	 * Channel instance storage.
	 *
	 * @var Channel_Store
	 */
	private $store;

	/**
	 * Registered channels.
	 *
	 * @var Channel_Registry
	 */
	private $registry;

	/**
	 * Dispatcher, to know which instances are available.
	 *
	 * @var Dispatcher
	 */
	private $dispatcher;

	/**
	 * Run state, where "skip what is pending" is recorded.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Notices shown after an action.
	 *
	 * @var Notices
	 */
	private $notices;

	/**
	 * Global settings, whose notify values the routing section points out.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Channel_Store    $store      Channel instance storage.
	 * @param Channel_Registry $registry   Registered channels.
	 * @param Dispatcher       $dispatcher Dispatcher.
	 * @param State            $state      Run state.
	 * @param Notices          $notices    Notices shown after an action.
	 * @param Settings         $settings   Global settings.
	 */
	public function __construct( Channel_Store $store, Channel_Registry $registry, Dispatcher $dispatcher, State $state, Notices $notices, Settings $settings ) {
		$this->store      = $store;
		$this->registry   = $registry;
		$this->dispatcher = $dispatcher;
		$this->state      = $state;
		$this->notices    = $notices;
		$this->settings   = $settings;
	}

	/**
	 * The address of the tab.
	 *
	 * @param array<string, string> $args Extra query arguments.
	 *
	 * @return string
	 */
	private function tab_url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'options-general.php?page=wp-updates-notifier&tab=channels' ) );
	}

	/**
	 * The address of the add form of a module.
	 *
	 * @param string $type Channel type.
	 *
	 * @return string
	 */
	private function new_url( string $type ): string {
		return $this->tab_url(
			array(
				'action' => 'new',
				'type'   => $type,
			)
		);
	}

	/**
	 * The address of the edit form of an instance.
	 *
	 * @param string $id Instance id.
	 *
	 * @return string
	 */
	private function edit_url( string $id ): string {
		return $this->tab_url(
			array(
				'action' => 'edit',
				'id'     => $id,
			)
		);
	}

	/**
	 * The instances that can be worked on: every stored or code-defined one whose module is registered.
	 *
	 * @return array<string, Channel_Instance>
	 */
	private function listed(): array {
		return array_filter( $this->store->all(), array( $this->dispatcher, 'is_available' ) );
	}

	/**
	 * Output markup that its builder has escaped.
	 *
	 * @param string $html The markup.
	 *
	 * @return void
	 */
	private function put( string $html ): void {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
	}

	/**
	 * Output the list of modules to choose from.
	 *
	 * @return void
	 */
	public function render_picker(): void {
		$channels = $this->registry->all();
		?>
		<div class="sc-wpun-module-picker">
			<h2><?php echo esc_html( self::picker_heading() ); ?></h2>
			<?php if ( array() === $channels ) : ?>
				<p><?php esc_html_e( 'No channel modules are available.', 'wp-updates-notifier' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Choose where the update notifications are to be sent.', 'wp-updates-notifier' ); ?></p>
				<ul class="sc-wpun-modules">
					<?php foreach ( $channels as $type => $channel ) : ?>
						<li data-type="<?php echo esc_attr( (string) $type ); ?>">
							<a href="<?php echo esc_url( $this->new_url( (string) $type ) ); ?>"><?php echo esc_html( $channel->get_label() ); ?></a>
							<?php if ( '' !== $channel->get_description() ) : ?>
								<span class="description"> &ndash; <?php echo esc_html( $channel->get_description() ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p><a class="button" href="<?php echo esc_url( $this->tab_url() ); ?>"><?php esc_html_e( 'Cancel', 'wp-updates-notifier' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * The title of the module picker.
	 *
	 * @return string
	 */
	public static function picker_heading(): string {
		return __( 'Add channel', 'wp-updates-notifier' );
	}

	/**
	 * What a new or edit form is called and whether it would show errors, for the page title.
	 *
	 * Does not use up the errors of a failed save: the form still shows them.
	 *
	 * @param string $mode `new` or `edit`.
	 * @param string $key  Channel type for `new`, instance id for `edit`.
	 *
	 * @return array{name: string, errors: bool}|null Null if no form would be drawn for it.
	 */
	public function view( string $mode, string $key ): ?array {
		if ( 'new' === $mode ) {
			$channel = $this->registry->get( $key );
			if ( null === $channel ) {
				return null;
			}

			return array(
				'name'   => $this->heading( $channel, null ),
				'errors' => $this->has_errors( 'new:' . $key ),
			);
		}

		$instance = $this->listed()[ $key ] ?? null;
		$channel  = null === $instance ? null : $this->registry->get( $instance->get_type() );
		if ( null === $instance || null === $channel || $this->store->is_code_defined( $key ) ) {
			return null;
		}

		return array(
			'name'   => $this->heading( $channel, $instance ),
			'errors' => $this->has_errors( 'edit:' . $key ),
		);
	}

	/**
	 * The title of a form: also its h2.
	 *
	 * @param Channel               $channel  The module.
	 * @param Channel_Instance|null $instance The instance being edited; null for a new one.
	 *
	 * @return string
	 */
	private function heading( Channel $channel, ?Channel_Instance $instance ): string {
		if ( null === $instance ) {
			// translators: %s: Name of the channel module, for example "Email".
			return sprintf( __( 'Add %s channel', 'wp-updates-notifier' ), $channel->get_label() );
		}

		// translators: %s: Label of the channel.
		return sprintf( __( 'Edit %s', 'wp-updates-notifier' ), $instance->get_label() );
	}

	/**
	 * Whether the last failed save was for this form and left errors.
	 *
	 * @param string $form `edit:<id>` or `new:<type>`.
	 *
	 * @return bool
	 */
	private function has_errors( string $form ): bool {
		$round = $this->round_trip( $form, false );

		return null !== $round && array() !== $round['errors'];
	}

	/**
	 * Output the form to add an instance of a module.
	 *
	 * @param string $type Channel type.
	 *
	 * @return string|null Why nothing was drawn: null if the form is drawn.
	 */
	public function render_new( string $type ): ?string {
		$channel = $this->registry->get( $type );
		if ( null === $channel ) {
			return __( 'There is no such channel module.', 'wp-updates-notifier' );
		}

		$settings = array();
		foreach ( $channel->get_form_fields() as $name => $schema ) {
			if ( is_array( $schema ) && array_key_exists( 'default', $schema ) ) {
				$settings[ (string) $name ] = $schema['default'];
			}
		}
		$label       = $channel->get_label();
		$provisional = new Channel_Instance( self::generate_id( $label, $type, $this->taken_ids() ), $type, $label, false, $settings, array() );

		$this->render_form( 'new', $channel, $provisional );

		return null;
	}

	/**
	 * Output the form to edit an instance.
	 *
	 * @param string $id Instance id.
	 *
	 * @return string|null Why nothing was drawn: null if the form is drawn.
	 */
	public function render_edit( string $id ): ?string {
		$instance = $this->listed()[ $id ] ?? null;
		if ( null === $instance ) {
			return __( 'There is no such channel.', 'wp-updates-notifier' );
		}
		if ( $this->store->is_code_defined( $id ) ) {
			// translators: %s: Label of the channel.
			return sprintf( __( '%s is defined in code and cannot be changed here.', 'wp-updates-notifier' ), $instance->get_label() );
		}
		$channel = $this->registry->get( $instance->get_type() );
		if ( null === $channel ) {
			return __( 'There is no such channel.', 'wp-updates-notifier' );
		}

		$this->render_form( 'edit', $channel, $instance );

		return null;
	}

	/**
	 * Output a form.
	 *
	 * @param string           $mode     `new` or `edit`.
	 * @param Channel          $channel  The module.
	 * @param Channel_Instance $instance The effective instance, or the provisional one of a new channel.
	 *
	 * @return void
	 */
	private function render_form( string $mode, Channel $channel, Channel_Instance $instance ): void {
		$is_new   = 'new' === $mode;
		$id       = $instance->get_id();
		$type     = $instance->get_type();
		$form_key = $is_new ? 'new:' . $type : 'edit:' . $id;
		$fields   = $channel->get_form_fields();
		$round    = $this->round_trip( $form_key, true );
		$errors   = null === $round ? array() : $round['errors'];

		$was_enabled = $instance->is_enabled();
		$label       = $instance->get_label();
		$enabled     = $was_enabled;
		$skip        = false;
		$routing     = Routing::normalise( $instance->get_routing() );
		$form_inst   = $instance;
		if ( null !== $round ) {
			$values   = $round['values'];
			$label    = isset( $values['label'] ) && is_string( $values['label'] ) ? $values['label'] : $label;
			$enabled  = ! empty( $values['enabled'] );
			$skip     = ! empty( $values['skip_pending'] );
			$routing  = Routing::normalise( array_merge( $routing, isset( $values['routing'] ) && is_array( $values['routing'] ) ? $values['routing'] : array() ) );
			$settings = array_merge( $instance->get_settings(), isset( $values['settings'] ) && is_array( $values['settings'] ) ? $values['settings'] : array() );

			if ( ! $is_new && null !== $this->store->external_source( $id, 'enabled' ) ) {
				$enabled = $was_enabled;
			}
			$form_inst = $instance->with_label( $label )->with_enabled( $enabled )->with_settings( $settings )->with_routing( $routing );
		}
		$settings = $form_inst->get_settings();
		$custom   = $channel->render_settings( $form_inst );
		// A module that draws its own fields has no field ids of ours to link its errors to.
		$linkable = is_string( $custom ) ? array() : $fields;

		$heading = $this->heading( $channel, $is_new ? null : $instance );
		?>
		<div class="sc-wpun-channel-form-wrap">
			<h2><?php echo esc_html( $heading ); ?></h2>
			<?php $this->render_error_summary( $errors, $linkable ); ?>
			<form class="sc-wpun-channel-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<input type="hidden" name="mode" value="<?php echo esc_attr( $mode ); ?>" />
				<input type="hidden" name="<?php echo esc_attr( $is_new ? 'type' : 'id' ); ?>" value="<?php echo esc_attr( $is_new ? $type : $id ); ?>" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( self::SAVE_ACTION . '_' . $mode . '_' . ( $is_new ? $type : $id ) ) ); ?>" />
				<?php
				/**
				 * Fires inside the add and edit form of a channel, before its first section.
				 *
				 * Output extra markup here. The plugin does not save extra inputs. On the form of a new channel
				 * the instance is a provisional, unsaved one: the id it would get from the prefilled label, the type,
				 * the label of the module, switched off, with the default settings.
				 *
				 * Example, add a note:
				 *
				 *     add_action(
				 *         'sc_wpun_before_channel_form',
				 *         function ( $instance, $channel ) {
				 *             echo '<p>' . esc_html( $channel->get_label() ) . '</p>';
				 *         },
				 *         10,
				 *         2
				 *     );
				 *
				 * `$instance` holds the resolved settings, secrets included: don't log or dump its settings.
				 *
				 * @since 2.0.0
				 *
				 * @param Channel_Instance $instance The instance being edited, or the provisional one of a new channel.
				 * @param Channel          $channel  The module of the instance.
				 */
				do_action( 'sc_wpun_before_channel_form', $form_inst, $channel );

				$this->render_general_section( $is_new, $instance, $label, $enabled, $skip, $was_enabled, $errors );
				$this->render_routing_section( $routing, $errors );
				$this->render_settings_section( $custom, $form_inst, $fields, $settings, $is_new, $errors );

				/**
				 * Fires inside the add and edit form of a channel, after its last section and before the buttons.
				 *
				 * On the form of a new channel the instance is a provisional, unsaved one: the id it would get from the
				 * prefilled label, the type, the label of the module, switched off, with the default settings.
				 *
				 * Example, add a note:
				 *
				 *     add_action(
				 *         'sc_wpun_after_channel_form',
				 *         function ( $instance, $channel ) {
				 *             echo '<p>' . esc_html( $instance->get_id() ) . '</p>';
				 *         },
				 *         10,
				 *         2
				 *     );
				 *
				 * `$instance` holds the resolved settings, secrets included: don't log or dump its settings.
				 *
				 * @since 2.0.0
				 *
				 * @param Channel_Instance $instance The instance being edited, or the provisional one of a new channel.
				 * @param Channel          $channel  The module of the instance.
				 */
				do_action( 'sc_wpun_after_channel_form', $form_inst, $channel );
				?>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php echo esc_html( $is_new ? __( 'Add channel', 'wp-updates-notifier' ) : __( 'Save channel', 'wp-updates-notifier' ) ); ?></button>
					<a class="button" href="<?php echo esc_url( $this->tab_url() ); ?>"><?php esc_html_e( 'Cancel', 'wp-updates-notifier' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Output the summary of the errors of the last save.
	 *
	 * @param array<string, string>               $errors Messages by field key.
	 * @param array<string, array<string, mixed>> $fields The fields of the module that are drawn by the form, so an error can link to them.
	 *
	 * @return void
	 */
	private function render_error_summary( array $errors, array $fields ): void {
		if ( array() === $errors ) {
			return;
		}
		?>
		<div class="notice notice-error inline sc-wpun-form-errors" role="alert" tabindex="-1">
			<h2><?php esc_html_e( 'There is a problem', 'wp-updates-notifier' ); ?></h2>
			<p><?php esc_html_e( 'The channel was not saved. Please correct the following.', 'wp-updates-notifier' ); ?></p>
			<ul>
				<?php foreach ( $errors as $key => $message ) : ?>
					<li>
						<?php $anchor = $this->field_id( (string) $key, $fields ); ?>
						<?php if ( '' === $anchor ) : ?>
							<?php echo esc_html( $message ); ?>
						<?php else : ?>
							<a href="#<?php echo esc_attr( $anchor ); ?>"><?php echo esc_html( $message ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * The id of the control an error key links to: the control itself, or the first input of a group of radio buttons or checkboxes.
	 *
	 * @param string                              $key    `label`, `enabled`, `routing.<name>` or `settings.<name>`.
	 * @param array<string, array<string, mixed>> $fields The fields of the module.
	 *
	 * @return string Empty for an error that names no field.
	 */
	private function field_id( string $key, array $fields ): string {
		if ( 'label' === $key ) {
			return 'sc_wpun_channel_label';
		}
		if ( 'enabled' === $key ) {
			return $this->first_control_id( Channel::enabled_field(), 'sc_wpun_channel_enabled' );
		}
		if ( 0 === strpos( $key, 'routing.' ) ) {
			$name = substr( $key, 8 );

			return $this->first_control_id( Routing::fields()[ $name ] ?? array(), 'sc_wpun_channel_routing_' . $name );
		}
		if ( 0 === strpos( $key, 'settings.' ) && isset( $fields[ substr( $key, 9 ) ] ) ) {
			$name = substr( $key, 9 );

			return $this->first_control_id( $fields[ $name ], 'sc_wpun_channel_settings_' . $name );
		}

		return '';
	}

	/**
	 * Where focus goes for a field: its id, or for a group of radio buttons or checkboxes the first input, as the fieldset takes no focus.
	 *
	 * A boolean shown as a checkbox is one control with the id itself; the other groups number their inputs `{id}_0`, `{id}_1` and so on.
	 *
	 * @param array<string, mixed> $schema Field schema.
	 * @param string               $id     Id of the field.
	 *
	 * @return string
	 */
	private function first_control_id( array $schema, string $id ): string {
		$single_checkbox = 'boolean' === Field_Types::canonical( $schema['type'] ?? null ) && 'checkbox' === Boolean_Field::normalise( $schema )['display'];

		return $single_checkbox || $this->labels_one_control( $schema ) ? $id : $id . '_0';
	}

	/**
	 * Output a row of a form table.
	 *
	 * @param string $title    Text of the row header, already escaped.
	 * @param string $target      Id of the single control the header labels; empty if the control names itself.
	 * @param string $controls Markup of the cell, already escaped.
	 * @param bool   $required Whether to mark the field as required.
	 *
	 * @return void
	 */
	private function row( string $title, string $target, string $controls, bool $required = false ): void {
		$title .= $required ? Field_Renderer::required_marker() : '';
		$this->put(
			'<tr><th scope="row">' . ( '' === $target ? $title : '<label for="' . esc_attr( $target ) . '">' . $title . '</label>' ) . '</th><td>' . $controls . '</td></tr>'
		);
	}

	/**
	 * Whether the header of a field's row is a label for a single control.
	 *
	 * Checkboxes and radio buttons have their own labels, and a group of them is named by its legend.
	 *
	 * @param array<string, mixed> $schema Field schema.
	 *
	 * @return bool
	 */
	private function labels_one_control( array $schema ): bool {
		$type = Field_Types::canonical( $schema['type'] ?? null );
		if ( 'boolean' === $type ) {
			return 'select' === Boolean_Field::normalise( $schema )['display'];
		}
		if ( 'enum' === $type ) {
			return 'select' === Enum_Field::normalise( $schema )['display'];
		}

		return true;
	}

	/**
	 * Output a field with its error and its note on where its value comes from.
	 *
	 * @param array<string, mixed> $schema   Field schema.
	 * @param string               $name     Input name.
	 * @param string               $id       Control id.
	 * @param mixed                $value    Current value.
	 * @param string               $error    Error message, if any.
	 * @param string|null          $external Where the value comes from, if outside the database.
	 *
	 * @return string The markup of the cell.
	 */
	private function control( array $schema, string $name, string $id, $value, string $error, ?string $external = null ): string {
		$description = isset( $schema['description'] ) && is_string( $schema['description'] ) && '' !== $schema['description'] ? $schema['description'] : '';
		if ( null !== $external && Field_Types::hides_external( $schema ) ) {
			// A value from outside the database may be a secret the schema forgot to flag: don't print it.
			$value = '';
		}
		// The field adds the id of its error after these.
		$described_by = trim( ( '' !== $description ? $id . '-description' : '' ) . ( null !== $external ? ' ' . $id . '-external' : '' ) );
		$html         = Field_Renderer::field( $schema, $name, $id, $value, null !== $external, $described_by, array(), $error );
		if ( null !== $external ) {
			// translators: %s: Where the value comes from, for example "constant SC_WPUN_SETTING_FREQUENCY".
			$html .= ' <span class="description sc-wpun-external" id="' . esc_attr( $id . '-external' ) . '">' . esc_html( sprintf( __( 'Set by %s', 'wp-updates-notifier' ), $external ) ) . '</span>';
		}
		if ( '' !== $description ) {
			$html .= '<p class="description" id="' . esc_attr( $id . '-description' ) . '">' . esc_html( $description ) . '</p>';
		}

		return $html;
	}

	/**
	 * Output the id, label, enabled and skip pending rows.
	 *
	 * @param bool                 $is_new      Whether the channel is new.
	 * @param Channel_Instance     $instance    The effective or provisional instance, before the submitted values.
	 * @param string               $label       Label to show.
	 * @param bool                 $enabled     Enabled switch to show.
	 * @param bool                 $skip        Whether "skip pending" is ticked.
	 * @param bool                 $was_enabled Whether the instance is enabled now.
	 * @param array<string,string> $errors      Messages by field key.
	 *
	 * @return void
	 */
	private function render_general_section( bool $is_new, Channel_Instance $instance, string $label, bool $enabled, bool $skip, bool $was_enabled, array $errors ): void {
		$id       = $instance->get_id();
		$external = $is_new ? null : $this->store->external_source( $id, 'enabled' );
		?>
		<h3><?php esc_html_e( 'Channel', 'wp-updates-notifier' ); ?></h3>
		<table class="form-table" role="presentation">
			<tbody>
				<?php
				if ( $is_new ) {
					$this->row( esc_html__( 'ID', 'wp-updates-notifier' ), '', '<p class="description">' . esc_html__( 'The id is made from the label when the channel is saved and cannot be changed afterwards.', 'wp-updates-notifier' ) . '</p>' );
				} else {
					$this->row(
						esc_html__( 'ID', 'wp-updates-notifier' ),
						'',
						'<code class="sc-wpun-channel-id">' . esc_html( $id ) . '</code><p class="description">'
						// translators: %s: Start of the names of the constants, for example "SC_WPUN_CHANNEL_EMAIL__".
						. sprintf( esc_html__( 'Constants for this channel start with %s', 'wp-updates-notifier' ), '<code>' . esc_html( 'SC_WPUN_CHANNEL_' . strtoupper( $id ) . '__' ) . '</code>' ) . '</p>'
					);
				}

				$label_schema = $this->label_schema();
				$this->row( esc_html( (string) $label_schema['label'] ), 'sc_wpun_channel_label', $this->control( $label_schema, 'label', 'sc_wpun_channel_label', $label, $errors['label'] ?? '' ), Field_Renderer::is_required( $label_schema ) );

				$enabled_schema = Channel::enabled_field();
				$this->row( esc_html( (string) $enabled_schema['label'] ), '', $this->control( $enabled_schema, 'enabled', 'sc_wpun_channel_enabled', $enabled, $errors['enabled'] ?? '', $external ) );

				if ( null === $external && ( $is_new || ! $was_enabled ) ) {
					$this->row(
						esc_html__( 'Updates already waiting', 'wp-updates-notifier' ),
						'',
						'<label for="sc_wpun_channel_skip_pending"><input type="checkbox" id="sc_wpun_channel_skip_pending" name="skip_pending" value="1" aria-describedby="sc_wpun_channel_skip_pending-description"' . checked( $skip, true, false ) . ' /> '
						. esc_html__( 'Don\'t send the updates that are already waiting', 'wp-updates-notifier' ) . '</label>'
						. '<p class="description" id="sc_wpun_channel_skip_pending-description">' . esc_html__( 'Only updates that appear after the channel is switched on are sent. Applies when the channel is saved switched on.', 'wp-updates-notifier' ) . '</p>'
					);
				}
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Output the routing rows.
	 *
	 * @param array<mixed>         $routing Normalised routing to show.
	 * @param array<string,string> $errors  Messages by field key.
	 *
	 * @return void
	 */
	private function render_routing_section( array $routing, array $errors ): void {
		$notes = $this->global_notes();
		?>
		<h3><?php esc_html_e( 'Routing', 'wp-updates-notifier' ); ?></h3>
		<table class="form-table" role="presentation">
			<tbody>
				<?php
				foreach ( Routing::fields() as $name => $schema ) {
					$id = 'sc_wpun_channel_routing_' . $name;
					if ( isset( $notes[ $name ] ) ) {
						$schema['description'] = trim( ( isset( $schema['description'] ) && is_string( $schema['description'] ) ? $schema['description'] : '' ) . ' ' . $notes[ $name ] );
					}
					$this->row(
						esc_html( isset( $schema['label'] ) && is_string( $schema['label'] ) ? $schema['label'] : $name ),
						$this->labels_one_control( $schema ) ? $id : '',
						$this->control( $schema, 'routing[' . $name . ']', $id, $routing[ $name ] ?? '', $errors[ 'routing.' . $name ] ?? '' ),
						Field_Renderer::is_required( $schema )
					);
				}
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Notes on what the global notify settings already limit, by routing field.
	 *
	 * @return array<string, string> Text by `scope` and `core`; a field with nothing to say is missing.
	 */
	private function global_notes(): array {
		$settings = $this->settings->get();
		$scope    = array();
		$notes    = array();

		if ( 'none' === $settings['notify_plugins'] ) {
			$scope[] = __( 'On the General tab, plugins are not checked.', 'wp-updates-notifier' );
		} elseif ( 'active' === $settings['notify_plugins'] ) {
			// translators: "All" and "Only active ones" are routing choices on this form: use the same translations as for those choices.
			$scope[] = __( 'On the General tab, only active plugins are checked, so "All" here acts like "Only active ones" for plugins.', 'wp-updates-notifier' );
		}
		if ( 'none' === $settings['notify_themes'] ) {
			$scope[] = __( 'On the General tab, themes are not checked.', 'wp-updates-notifier' );
		} elseif ( 'active' === $settings['notify_themes'] ) {
			// translators: "All" and "Only active ones" are routing choices on this form: use the same translations as for those choices.
			$scope[] = __( 'On the General tab, only active themes are checked, so "All" here acts like "Only active ones" for themes.', 'wp-updates-notifier' );
		}
		if ( array() !== $scope ) {
			$notes['scope'] = implode( ' ', $scope );
		}

		if ( 'none' === $settings['notify_core'] ) {
			$notes['core'] = __( 'On the General tab, WordPress updates are not checked.', 'wp-updates-notifier' );
		} elseif ( 'major' === $settings['notify_core'] ) {
			$notes['core'] = __( 'On the General tab, only major releases are checked.', 'wp-updates-notifier' );
		}

		return $notes;
	}

	/**
	 * Output the settings of the module: its own markup, or a row for each of its fields.
	 *
	 * @param string|null                         $custom   What the module's render_settings() returned.
	 * @param Channel_Instance                    $form_inst The instance with the submitted values.
	 * @param array<string, array<string, mixed>> $fields   The fields of the module.
	 * @param array<string, mixed>                $settings Values to show.
	 * @param bool                                $is_new   Whether the channel is new.
	 * @param array<string,string>                $errors   Messages by field key.
	 *
	 * @return void
	 */
	private function render_settings_section( ?string $custom, Channel_Instance $form_inst, array $fields, array $settings, bool $is_new, array $errors ): void {
		?>
		<h3><?php esc_html_e( 'Settings', 'wp-updates-notifier' ); ?></h3>
		<?php
		if ( is_string( $custom ) ) {
			?>
			<div class="sc-wpun-custom-settings">
				<?php echo $custom; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup of the channel module. ?>
			</div>
			<?php
			return;
		}
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<?php
				foreach ( $fields as $name => $schema ) {
					$name = (string) $name;
					if ( ! is_array( $schema ) ) {
						continue;
					}
					if ( null === Field_Types::get( $schema['type'] ?? null ) ) {
						Field_Types::unknown( $schema['type'] ?? null );
						continue;
					}

					$id       = 'sc_wpun_channel_settings_' . $name;
					$external = $is_new ? null : $this->store->external_source( $form_inst->get_id(), $name );
					$value    = array_key_exists( $name, $settings ) ? $settings[ $name ] : ( $schema['default'] ?? '' );
					$title    = isset( $schema['label'] ) && is_string( $schema['label'] ) && '' !== $schema['label'] ? $schema['label'] : $name;
					$this->row( esc_html( $title ), $this->labels_one_control( $schema ) ? $id : '', $this->control( $schema, 'settings[' . $name . ']', $id, $value, $errors[ 'settings.' . $name ] ?? '', $external ), Field_Renderer::is_required( $schema ) );
				}
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The schema of the label of an instance.
	 *
	 * @return array<string, mixed>
	 */
	private function label_schema(): array {
		return array(
			'type'     => 'text',
			'label'    => __( 'Label', 'wp-updates-notifier' ),
			'required' => true,
		);
	}

	/**
	 * Read what the last failed save left for the current user, if it is for this form, and delete it when asked to.
	 *
	 * @param string $form   `edit:<id>` or `new:<type>`.
	 * @param bool   $delete Whether to delete it: the form that shows it does, the page title only looks.
	 *
	 * @return array{values: array<string, mixed>, errors: array<string, string>}|null Null if there is none, it is for another site or form, or it is stale.
	 */
	private function round_trip( string $form, bool $delete ): ?array {
		$user_id = get_current_user_id();
		$stored  = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $stored ) || ( $stored['site'] ?? 0 ) !== get_current_blog_id() ) {
			return null; // None, or it belongs to another site of the network.
		}
		if ( $delete ) {
			delete_user_meta( $user_id, self::META_KEY );
		}
		if ( ! isset( $stored['time'] ) || ! is_int( $stored['time'] ) || $stored['time'] < time() - 5 * MINUTE_IN_SECONDS || ( $stored['form'] ?? '' ) !== $form ) {
			return null;
		}

		$errors = array();
		foreach ( isset( $stored['errors'] ) && is_array( $stored['errors'] ) ? $stored['errors'] : array() as $key => $message ) {
			if ( is_string( $message ) ) {
				$errors[ (string) $key ] = $message;
			}
		}

		return array(
			'values' => isset( $stored['values'] ) && is_array( $stored['values'] ) ? $stored['values'] : array(),
			'errors' => $errors,
		);
	}

	/**
	 * The ids no new instance may take: every stored or code-defined one, and `new`, which is the word of the add route.
	 *
	 * @return string[]
	 */
	private function taken_ids(): array {
		return array_map( 'strval', array_merge( array_keys( $this->store->stored_all() ), array_keys( $this->store->all() ), array( 'new' ) ) );
	}

	/**
	 * An id made from a label: lower case words joined with single underscores.
	 *
	 * @param string $text Label or type.
	 *
	 * @return string May be empty or not a valid id (a label without Latin letters or digits).
	 */
	private static function slug( string $text ): string {
		$text = (string) preg_replace( '/\s+/', '-', strtolower( $text ) );
		$text = str_replace( '-', '_', sanitize_key( $text ) );

		return trim( (string) preg_replace( '/_+/', '_', $text ), '_' );
	}

	/**
	 * The id of a new instance, from its label and not taken by another.
	 *
	 * @internal
	 *
	 * @param string   $label Label.
	 * @param string   $type  Channel type, used when the label gives no id.
	 * @param string[] $taken Ids that are taken.
	 *
	 * @return string `ops_team` for "Ops Team!", then `ops_team_2` and so on.
	 */
	public static function generate_id( string $label, string $type, array $taken ): string {
		$base = self::slug( $label );
		if ( 1 !== preg_match( self::ID_PATTERN, $base ) ) {
			$base = self::slug( $type );
		}
		if ( 1 !== preg_match( self::ID_PATTERN, $base ) ) {
			$base = 'channel';
		}

		$id = $base;
		$n  = 2;
		while ( in_array( $id, $taken, true ) ) {
			$id = $base . '_' . $n;
			++$n;
		}

		return $id;
	}

	/**
	 * A key of the request as a string, from the posted data, cleaned as a key.
	 *
	 * @param string $key Name in `$_POST`.
	 *
	 * @return string Empty if missing or not a string.
	 */
	private function posted_key( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only used to find the nonce action.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * A list in the posted data, unslashed.
	 *
	 * @param string $key Name in `$_POST`.
	 *
	 * @return array<string, mixed> Empty if missing or not a list.
	 */
	private function posted_list( string $key ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Called after the nonce is checked; the field types clean the values.
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}
		$list = wp_unslash( $_POST[ $key ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return is_array( $list ) ? $list : array();
	}

	/**
	 * Check a submitted form and save the channel, or remember what was wrong.
	 *
	 * @return string|null The address to go to; null if the user may not do this or the nonce is not valid.
	 */
	public function process_save(): ?string {
		if ( ! current_user_can( Settings_Page::capability() ) || ! isset( $_POST['_wpnonce'] ) || ! is_string( $_POST['_wpnonce'] ) ) {
			return null;
		}

		$mode = $this->posted_key( 'mode' );
		if ( 'new' !== $mode && 'edit' !== $mode ) {
			return null;
		}
		$key = $this->posted_key( 'new' === $mode ? 'type' : 'id' );
		if ( false === wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), self::SAVE_ACTION . '_' . $mode . '_' . $key ) ) {
			return null;
		}

		$stored    = null;
		$effective = null;
		if ( 'new' === $mode ) {
			$channel = $this->registry->get( $key );
			if ( null === $channel ) {
				$this->notices->add( false, __( 'There is no such channel module.', 'wp-updates-notifier' ) );
				return $this->tab_url();
			}
		} else {
			$effective = $this->listed()[ $key ] ?? null;
			if ( null === $effective ) {
				$this->notices->add( false, __( 'There is no such channel.', 'wp-updates-notifier' ) );
				return $this->tab_url();
			}
			if ( $this->store->is_code_defined( $key ) ) {
				// translators: %s: Label of the channel.
				$this->notices->add( false, sprintf( __( '%s is defined in code and cannot be changed here.', 'wp-updates-notifier' ), $effective->get_label() ) );
				return $this->tab_url();
			}
			$stored  = $this->store->stored_all()[ $key ] ?? null;
			$channel = $this->registry->get( $effective->get_type() );
			if ( null === $stored || null === $channel ) {
				$this->notices->add( false, __( 'There is no such channel.', 'wp-updates-notifier' ) );
				return $this->tab_url();
			}
		}

		$fields          = $channel->get_form_fields();
		$errors          = array();
		$posted_settings = $this->posted_list( 'settings' );

		// Label.
		$label_schema = $this->label_schema();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked above; the text field type cleans it.
		$posted_label = isset( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : '';
		$checked      = Field_Types::validate( array( 'label' => $label_schema ), array( 'label' => $posted_label ), array() );
		$label        = is_array( $checked ) && isset( $checked['label'] ) && is_string( $checked['label'] ) ? $checked['label'] : '';
		$this->collect_errors( $errors, $checked, 'label', array() );

		// Enabled: a missing value is off; one supplied from outside the database wins.
		$enabled = false;
		if ( null !== $effective && null !== $this->store->external_source( $key, 'enabled' ) ) {
			$enabled = $effective->is_enabled();
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked above; the boolean field type cleans it.
			$posted_enabled = isset( $_POST['enabled'] ) ? wp_unslash( $_POST['enabled'] ) : '0';
			$checked        = Field_Types::validate( array( 'enabled' => Channel::enabled_field() ), array( 'enabled' => $posted_enabled ), array() );
			$enabled        = is_array( $checked ) && true === ( $checked['enabled'] ?? false );
			$this->collect_errors( $errors, $checked, 'enabled', array() );
		}

		// Routing: unknown keys of the stored routing are kept.
		$posted_routing = $this->posted_list( 'routing' );
		$checked        = Field_Types::validate( Routing::fields(), $posted_routing, array() );
		$routing        = array_merge( null === $stored ? array() : $stored->get_routing(), is_array( $checked ) ? $checked : array() );
		$this->collect_errors( $errors, $checked, 'routing', Routing::fields() );

		// Settings: a value from outside the database is not submitted, so the channel is given the one in effect.
		$stored_settings = null === $stored ? array() : $stored->get_settings();
		$for_validation  = $posted_settings;
		if ( null !== $effective ) {
			foreach ( array_keys( $fields ) as $name ) {
				$name = (string) $name;
				if ( null !== $this->store->external_source( $key, $name ) ) {
					$for_validation[ $name ] = $effective->get_setting( $name );
				}
			}
		}
		$clean = $channel->validate_settings( $for_validation, $stored_settings );
		if ( $clean instanceof \WP_Error ) {
			$this->collect_errors( $errors, $clean, 'settings', $fields );
			$clean = array();
		} elseif ( ! is_array( $clean ) ) {
			$errors[''] ??= __( 'The settings are not valid.', 'wp-updates-notifier' );
			$clean        = array();
		}

		if ( array() !== $errors ) {
			$this->remember(
				'new' === $mode ? 'new:' . $key : 'edit:' . $key,
				array(
					'label'        => is_string( $posted_label ) ? $posted_label : '',
					'enabled'      => $enabled,
					'skip_pending' => ! empty( $_POST['skip_pending'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
					'routing'      => $posted_routing,
					'settings'     => $this->without_secrets( $posted_settings, $fields ),
				),
				$errors
			);

			return 'new' === $mode ? $this->new_url( $key ) : $this->edit_url( $key );
		}

		$settings = array_merge( $stored_settings, $clean );
		if ( null === $stored ) {
			$id       = self::generate_id( $label, $key, $this->taken_ids() );
			$instance = new Channel_Instance( $id, $key, $label, $enabled, $settings, $routing );
		} else {
			$id       = $key;
			$instance = $stored->with_label( $label )->with_enabled( $enabled )->with_settings( $settings )->with_routing( $routing );
		}

		$was_enabled = null !== $effective && $effective->is_enabled();
		$this->store->save( $instance );

		$saved = $this->store->get( $id );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		if ( ! empty( $_POST['skip_pending'] ) && ! $was_enabled && null !== $saved && $saved->is_enabled() && null === $this->store->external_source( $id, 'enabled' ) ) {
			$this->state->skip_pending( $id );
		}

		$this->notices->add(
			true,
			null === $stored
				// translators: %s: Label of the channel.
				? sprintf( __( '%s added.', 'wp-updates-notifier' ), $label )
				// translators: %s: Label of the channel.
				: sprintf( __( '%s saved.', 'wp-updates-notifier' ), $label )
		);

		return $this->tab_url();
	}

	/**
	 * Add the messages of the errors of a validation to a list keyed by field.
	 *
	 * @param array<string, string>               $errors Messages by field key, added to.
	 * @param mixed                               $result The result of the validation.
	 * @param string                              $area   `label`, `enabled`, `routing` or `settings`.
	 * @param array<string, array<string, mixed>> $known  The fields of the area that can have an error; an error that names another has no field.
	 *
	 * @return void
	 */
	private function collect_errors( array &$errors, $result, string $area, array $known ): void {
		if ( ! $result instanceof \WP_Error ) {
			return;
		}

		foreach ( $result->get_error_codes() as $code ) {
			$data  = $result->get_error_data( $code );
			$field = is_array( $data ) && isset( $data['field'] ) && is_string( $data['field'] ) ? $data['field'] : '';
			if ( 'label' === $area || 'enabled' === $area ) {
				$key = $area;
			} else {
				$key = '' !== $field && isset( $known[ $field ] ) ? $area . '.' . $field : '';
			}
			$errors[ $key ] ??= $result->get_error_message( $code );
		}
	}

	/**
	 * The posted settings without the values of secrets and without the "clear" boxes.
	 *
	 * @param array<string, mixed>                $settings Posted settings.
	 * @param array<string, array<string, mixed>> $fields   The fields of the module.
	 *
	 * @return array<string, mixed>
	 */
	private function without_secrets( array $settings, array $fields ): array {
		foreach ( array_keys( $settings ) as $name ) {
			$name = (string) $name;
			if ( '__clear' === substr( $name, -7 ) || ( isset( $fields[ $name ] ) && is_array( $fields[ $name ] ) && Field_Types::is_secret( $fields[ $name ] ) ) ) {
				unset( $settings[ $name ] );
			}
		}

		return $settings;
	}

	/**
	 * Keep what was submitted and what was wrong for the form to show on the next page load.
	 *
	 * @param string               $form   `edit:<id>` or `new:<type>`.
	 * @param array<string, mixed> $values The submitted values.
	 * @param array<string,string> $errors Messages by field key.
	 *
	 * @return void
	 */
	private function remember( string $form, array $values, array $errors ): void {
		update_user_meta(
			get_current_user_id(),
			self::META_KEY,
			array(
				'site'   => get_current_blog_id(),
				'time'   => time(),
				'form'   => $form,
				'values' => $values,
				'errors' => $errors,
			)
		);
	}

	/**
	 * Handle the add and edit form (admin-post.php), then go to the next page.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		$url = $this->process_save();
		if ( null === $url ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wp-updates-notifier' ), '', array( 'response' => 403 ) );
		}

		wp_safe_redirect( $url );
		exit;
	}
}
