<?php
/**
 * The panels and forms of the settings groups add-ons register.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

use WPUN\Field_Types;
use WPUN\Network_Context;
use WPUN\Settings_Groups;

/**
 * Draws each settings group as a panel with a form of its own, on the General tab or on a tab of its own, and saves it.
 *
 * Nothing needs JavaScript. A form that fails validation is stored for the current user, and drawn again with the
 * submitted values (a secret is never kept) and an error on each field at fault.
 *
 * @internal
 */
class Settings_Group_Form {
	const SAVE_ACTION = 'sc_wpun_save_settings_group';
	const META_KEY    = 'sc_wpun_settings_group_form';
	const POST_KEY    = 'sc_wpun_group';

	/**
	 * The registered groups.
	 *
	 * @var Settings_Groups
	 */
	private $groups;

	/**
	 * Notices shown after an action.
	 *
	 * @var Notices
	 */
	private $notices;

	/**
	 * Where the plugin runs.
	 *
	 * @var Network_Context
	 */
	private $network_context;

	/**
	 * Constructor.
	 *
	 * @param Settings_Groups      $groups  The registered groups.
	 * @param Notices              $notices Notices shown after an action.
	 * @param Network_Context|null $network_context Where the plugin runs.
	 */
	public function __construct( Settings_Groups $groups, Notices $notices, ?Network_Context $network_context = null ) {
		$this->groups          = $groups;
		$this->notices         = $notices;
		$this->network_context = $network_context ?? new Network_Context();
	}

	/**
	 * The tabs the groups ask for besides General, as slug => label.
	 *
	 * The label is the `tab_label` of the first group on the tab that gave one, else the label of its first group.
	 *
	 * @return array<string, string>
	 */
	public function tab_labels(): array {
		$tabs = array();
		foreach ( $this->groups->all() as $group ) {
			if ( 'general' === $group['tab'] ) {
				continue;
			}
			if ( ! isset( $tabs[ $group['tab'] ] ) || ( '' !== $group['tab_label'] && ! $tabs[ $group['tab'] ]['explicit'] ) ) {
				$tabs[ $group['tab'] ] = array(
					'label'    => '' !== $group['tab_label'] ? $group['tab_label'] : $group['label'],
					'explicit' => '' !== $group['tab_label'],
				);
			}
		}

		return array_map(
			static fn( array $tab ): string => $tab['label'],
			$tabs
		);
	}

	/**
	 * Draw the groups of the tab whose action is running.
	 *
	 * @return void
	 */
	public function render_current_tab(): void {
		$this->render_tab( substr( (string) current_action(), strlen( 'sc_wpun_settings_tab_' ) ) );
	}

	/**
	 * Draw every group on a tab.
	 *
	 * @param string $tab Tab slug.
	 *
	 * @return void
	 */
	public function render_tab( string $tab ): void {
		foreach ( $this->groups->all() as $id => $group ) {
			if ( $tab === $group['tab'] ) {
				$this->render_group( (string) $id, $group );
			}
		}
	}

	/**
	 * The capability needed to save a group: the screen's, and the group's own on top of it.
	 *
	 * @param array<string, mixed> $group The group.
	 *
	 * @return bool
	 */
	private function may_save( array $group ): bool {
		return current_user_can( Settings_Page::capability() ) && ( '' === $group['capability'] || current_user_can( $group['capability'] ) );
	}

	/**
	 * The address of the tab a group is on.
	 *
	 * @param array<string, mixed> $group The group.
	 *
	 * @return string
	 */
	private function tab_url( array $group ): string {
		return $this->network_context->settings_url( $group['tab'] );
	}

	/**
	 * The DOM id of a field of a group.
	 *
	 * @param string $group Group id.
	 * @param string $name  Field name.
	 *
	 * @return string
	 */
	private function dom_id( string $group, string $name ): string {
		return 'sc_wpun_group_' . $group . '_' . $name;
	}

	/**
	 * Output a group: its errors, then its form.
	 *
	 * @param string               $id    Group id.
	 * @param array<string, mixed> $group The group.
	 *
	 * @return void
	 */
	private function render_group( string $id, array $group ): void {
		$fields    = $group['fields'];
		$round     = Form_Parts::round_trip( self::META_KEY, $id, true );
		$errors    = null === $round ? array() : $round['errors'];
		$submitted = null === $round ? array() : $round['values'];
		$editable  = $this->may_save( $group );
		$dom_id    = 'sc_wpun_group_' . $id;
		$text      = $group['description'];
		?>
		<div class="sc-wpun-group" id="<?php echo esc_attr( $dom_id ); ?>">
			<?php
			Form_Parts::error_summary(
				$errors,
				fn( string $key ): string => isset( $fields[ $key ] ) ? Form_Parts::first_control_id( $fields[ $key ], $this->dom_id( $id, $key ) ) : '',
				__( 'The settings were not saved. Please correct the following.', 'wp-updates-notifier' )
			);
			?>
			<form class="sc-wpun-group-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<input type="hidden" name="group" value="<?php echo esc_attr( $id ); ?>" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( self::SAVE_ACTION . '_' . $id ) ); ?>" />
				<fieldset class="sc-wpun-panel"<?php echo '' !== $text ? ' aria-describedby="' . esc_attr( $dom_id . '-description' ) . '"' : ''; ?>>
					<legend><h2><?php echo esc_html( $group['label'] ); ?></h2></legend>
					<?php if ( '' !== $text ) : ?>
						<p class="description" id="<?php echo esc_attr( $dom_id . '-description' ); ?>"><?php echo esc_html( $text ); ?></p>
					<?php endif; ?>
					<?php if ( ! $editable ) : ?>
						<p class="description sc-wpun-group-readonly"><?php esc_html_e( 'You can see these settings but you are not allowed to change them.', 'wp-updates-notifier' ); ?></p>
					<?php endif; ?>
					<?php $this->render_fields( $id, $group, $submitted, $errors, $editable ); ?>
				</fieldset>
				<?php if ( $editable ) : ?>
					<?php submit_button( __( 'Save settings', 'wp-updates-notifier' ), 'primary', 'submit', true, array( 'id' => $dom_id . '-submit' ) ); ?>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Output the rows of a group: the fields without a field group, then each field group that has fields.
	 *
	 * @param string               $id        Group id.
	 * @param array<string, mixed> $group     The group.
	 * @param array<string, mixed> $submitted The values of the last failed save.
	 * @param array<string,string> $errors    Messages by field name.
	 * @param bool                 $editable  Whether the user may save the group.
	 *
	 * @return void
	 */
	private function render_fields( string $id, array $group, array $submitted, array $errors, bool $editable ): void {
		$fields  = $group['fields'];
		$buckets = array();
		foreach ( $fields as $name => $schema ) {
			$name  = (string) $name;
			$entry = $this->groups->entry( $id, $name, __METHOD__ );
			if ( null === $entry || ( false === ( $schema['storage'] ?? true ) && null === $entry['source'] ) ) {
				continue; // A code-only field is shown only while something supplies it.
			}

			$value = $entry['value'];
			if ( null === $entry['source'] && array_key_exists( $name, $submitted ) ) {
				$value = $submitted[ $name ];
			}
			$field_group = isset( $schema['group'] ) && is_string( $schema['group'] ) && isset( $group['field_groups'][ $schema['group'] ] ) ? $schema['group'] : '';
			$dom_id      = $this->dom_id( $id, $name );
			$control     = Form_Parts::control( $schema, self::POST_KEY . '[' . $name . ']', $dom_id, $value, $errors[ $name ] ?? '', $entry['source'], Form_Parts::applies_note( $name, $schema, $fields ), ! $editable );

			$buckets[ $field_group ][ $name ] = Form_Parts::field_row( $name, $schema, $dom_id, $control );
		}

		$this->put_table( $buckets[''] ?? array() );
		foreach ( $group['field_groups'] as $field_group => $details ) {
			$rows = $buckets[ (string) $field_group ] ?? array();
			if ( array() === $rows ) {
				continue;
			}

			$collapsed = true === ( $details['collapsed'] ?? false );
			$open      = array() !== array_intersect_key( $errors, $rows );
			$dom_id    = 'sc_wpun_group_' . $id . '_group_' . preg_replace( '/[^A-Za-z0-9_-]/', '_', (string) $field_group );

			echo Form_Parts::frame_open( $dom_id, $details['label'], isset( $details['description'] ) && is_string( $details['description'] ) ? $details['description'] : '', $collapsed, $open, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
			$this->put_table( $rows );
			echo Form_Parts::frame_close( $collapsed ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup without input.
		}
	}

	/**
	 * Output a form table of rows, unless there are none.
	 *
	 * @param array<string, string> $rows The markup of the rows, escaped.
	 *
	 * @return void
	 */
	private function put_table( array $rows ): void {
		if ( array() !== $rows ) {
			echo '<table class="form-table" role="presentation"><tbody>' . implode( '', $rows ) . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
		}
	}

	/**
	 * Handle a group's form (admin-post.php), then go to the next page.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		Settings_Page::finish( $this->process_save() );
	}

	/**
	 * Check a submitted form and save the group, or remember what was wrong.
	 *
	 * @return string|null The address to go to; null if the user may not do this or the security token is not valid.
	 */
	public function process_save(): ?string {
		if ( ! current_user_can( Settings_Page::capability() ) ) {
			return null;
		}

		$id = Form_Parts::posted_key( 'group' );
		if ( ! Settings_Page::posted_token_valid( self::SAVE_ACTION . '_' . $id ) ) {
			return null;
		}

		$group = $this->groups->all()[ $id ] ?? null;
		if ( null === $group ) {
			$this->notices->add( false, __( 'There is no such settings group.', 'wp-updates-notifier' ) );
			return $this->network_context->settings_url();
		}
		if ( ! $this->may_save( $group ) ) {
			return null;
		}

		$stored = $this->groups->stored( $id );
		$errors = array();
		$posted = Form_Parts::posted_list( self::POST_KEY );
		$clean  = $this->validate( $id, $group, $posted, $stored, $errors );

		if ( array() !== $errors ) {
			Form_Parts::remember( self::META_KEY, $id, Form_Parts::without_secrets( $posted, $group['fields'] ), $errors );

			return $this->tab_url( $group );
		}

		$this->groups->save( $id, array_merge( $stored, $clean ) );

		/**
		 * Fires after the form of a settings group was saved with values that passed the checks.
		 *
		 * Both lists hold the stored values only (never a value supplied from a constant, an environment variable or a source),
		 * as they are in the option `sc_wpun_settings_<group>`. It also fires when nothing changed. Secrets are in the lists:
		 * don't log them.
		 *
		 * Example, forget a cache of your own when the group is saved:
		 *
		 *     add_action(
		 *         'sc_wpun_settings_group_saved',
		 *         function ( $group, $new, $old ) {
		 *             if ( 'acme' === $group && $new !== $old ) {
		 *                 delete_transient( 'acme_cache' );
		 *             }
		 *         },
		 *         10,
		 *         3
		 *     );
		 *
		 * @since 3.0.0
		 *
		 * @param string               $group The id of the settings group.
		 * @param array<string, mixed> $new   The stored values after the save.
		 * @param array<string, mixed> $old   The stored values before the save.
		 */
		do_action( 'sc_wpun_settings_group_saved', $id, $this->groups->stored( $id ), $stored );

		// translators: %s: Label of the settings group.
		$this->notices->add( true, sprintf( __( '%s settings saved.', 'wp-updates-notifier' ), $group['label'] ) );

		return $this->tab_url( $group );
	}

	/**
	 * Check the posted values of a group: each field, then the group's own check.
	 *
	 * @param string               $id     Group id.
	 * @param array<string, mixed> $group  The group.
	 * @param array<string, mixed> $posted The posted values.
	 * @param array<string, mixed> $stored The stored values.
	 * @param array<string,string> $errors Filled with the messages by field name; empty when everything is valid.
	 *
	 * @return array<string, mixed> The clean values of the fields that are stored and not supplied from outside the database.
	 */
	private function validate( string $id, array $group, array $posted, array $stored, array &$errors ): array {
		$errors = array();
		$fields = array();
		foreach ( $group['fields'] as $name => $schema ) {
			$name = (string) $name;
			if ( false !== ( $schema['storage'] ?? true ) && null === ( $this->groups->entry( $id, $name, __METHOD__ )['source'] ?? null ) ) {
				$fields[ $name ] = $schema;
			}
		}

		$clean = Field_Types::validate( $fields, $posted, $stored );
		if ( is_array( $clean ) && is_callable( $group['validate_callback'] ) ) {
			$clean = call_user_func( $group['validate_callback'], $clean, $stored );
		}

		if ( $clean instanceof \WP_Error ) {
			Form_Parts::collect_errors( $errors, $clean, '', $group['fields'] );

			return array();
		}
		if ( ! is_array( $clean ) ) {
			$errors[''] = __( 'The settings are not valid.', 'wp-updates-notifier' );

			return array();
		}

		return $clean;
	}
}
