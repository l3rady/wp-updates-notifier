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
use WPUN\Settings_Groups;

/**
 * Draws the settings groups as panels in the form of the General tab or of a tab of their own, and checks and stores what they post.
 *
 * Nothing needs JavaScript. A form that fails validation is stored for the current user, and drawn again with the
 * submitted values (a secret is never kept) and an error on each field at fault.
 *
 * @internal
 */
class Settings_Group_Form {
	const META_KEY = 'sc_wpun_settings_group_form';
	const POST_KEY = 'sc_wpun_group';

	/**
	 * The registered groups.
	 *
	 * @var Settings_Groups
	 */
	private $groups;

	/**
	 * Constructor.
	 *
	 * @param Settings_Groups $groups  The registered groups.
	 */
	public function __construct( Settings_Groups $groups ) {
		$this->groups = $groups;
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
	 * Draw the form of the tab whose action is running: the groups of the tab and one Save settings button.
	 *
	 * @return void
	 */
	public function render_current_tab(): void {
		$this->render_tab( substr( (string) current_action(), strlen( 'sc_wpun_settings_tab_' ) ) );
	}

	/**
	 * Draw the form of a tab of its own: every group registered for it, and one button when the user may save one.
	 *
	 * @param string $tab Tab slug.
	 *
	 * @return void
	 */
	public function render_tab( string $tab ): void {
		$round = $this->round( $tab );
		Settings_Page::form_open( $tab );
		$this->render_summary( $round['errors'] );
		$this->render_groups( $tab, $round );
		if ( $this->may_save_any( $tab ) ) {
			submit_button( __( 'Save settings', 'wp-updates-notifier' ) );
		}
		echo '</form>';
	}

	/**
	 * What the last failed save of a tab left for the current user. Reads it once: it is deleted.
	 *
	 * @param string $tab Tab slug.
	 *
	 * @return array{settings: array<string, mixed>, groups: array<string, mixed>, errors: array<string, string>}
	 */
	public function round( string $tab ): array {
		$round  = Form_Parts::round_trip( self::META_KEY, 'tab:' . $tab, true );
		$values = null === $round ? array() : $round['values'];

		return array(
			'settings' => isset( $values['settings'] ) && is_array( $values['settings'] ) ? $values['settings'] : array(),
			'groups'   => isset( $values['groups'] ) && is_array( $values['groups'] ) ? $values['groups'] : array(),
			'errors'   => null === $round ? array() : $round['errors'],
		);
	}

	/**
	 * Output the summary of the errors of the groups of a failed save.
	 *
	 * @param array<string, string> $errors Messages by `<group>/<field>`.
	 *
	 * @return void
	 */
	public function render_summary( array $errors ): void {
		$groups = $this->groups->all();
		Form_Parts::error_summary(
			$errors,
			function ( string $key ) use ( $groups ): string {
				$parts  = explode( '/', $key, 2 ) + array( '', '' );
				$schema = $groups[ $parts[0] ]['fields'][ $parts[1] ] ?? null;

				return is_array( $schema ) ? Form_Parts::first_control_id( $schema, $this->dom_id( $parts[0], $parts[1] ) ) : '';
			},
			__( 'The settings were not saved. Please correct the following.', 'wp-updates-notifier' )
		);
	}

	/**
	 * Draw the panels of every group on a tab, inside the form of the tab.
	 *
	 * @param string                                                                                             $tab   Tab slug.
	 * @param array{settings: array<string, mixed>, groups: array<string, mixed>, errors: array<string, string>} $round What the last failed save left, from round().
	 *
	 * @return void
	 */
	public function render_groups( string $tab, array $round ): void {
		foreach ( $this->groups->all() as $id => $group ) {
			if ( $tab === $group['tab'] ) {
				$this->render_group( (string) $id, $group, $round );
			}
		}
	}

	/**
	 * Whether the user may save at least one group on a tab.
	 *
	 * @param string $tab Tab slug.
	 *
	 * @return bool
	 */
	private function may_save_any( string $tab ): bool {
		foreach ( $this->groups->all() as $group ) {
			if ( $tab === $group['tab'] && $this->may_save( $group ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the tab is one the groups draw, so a form can be saved for it.
	 *
	 * @param string $tab Tab slug.
	 *
	 * @return bool
	 */
	public function has_tab( string $tab ): bool {
		return 'general' === $tab || isset( $this->tab_labels()[ $tab ] );
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
	 * Output a group: a panel for its fields without a field group, then a panel of its own for each field group.
	 *
	 * The group's panel is drawn only when it has something to show. The field groups are never inside it.
	 *
	 * @param string                                                                                             $id    Group id.
	 * @param array<string, mixed>                                                                               $group The group.
	 * @param array{settings: array<string, mixed>, groups: array<string, mixed>, errors: array<string, string>} $round What the last failed save left.
	 *
	 * @return void
	 */
	private function render_group( string $id, array $group, array $round ): void {
		$editable  = $this->may_save( $group );
		$dom_id    = 'sc_wpun_group_' . $id;
		$text      = $group['description'];
		$submitted = isset( $round['groups'][ $id ] ) && is_array( $round['groups'][ $id ] ) ? $round['groups'][ $id ] : array();
		$errors    = array();
		foreach ( $round['errors'] as $key => $message ) {
			if ( 0 === strpos( (string) $key, $id . '/' ) ) {
				$errors[ substr( (string) $key, strlen( $id ) + 1 ) ] = $message;
			}
		}
		$buckets = $this->rows( $id, $group, $submitted, $errors, $editable );
		?>
		<div class="sc-wpun-group" id="<?php echo esc_attr( $dom_id ); ?>">
			<?php if ( '' !== $text || ! $editable || array() !== ( $buckets[''] ?? array() ) ) : ?>
				<fieldset class="sc-wpun-panel"<?php echo '' !== $text ? ' aria-describedby="' . esc_attr( $dom_id . '-description' ) . '"' : ''; ?>>
					<legend><h2><?php echo esc_html( $group['label'] ); ?></h2></legend>
					<?php if ( '' !== $text ) : ?>
						<p class="description" id="<?php echo esc_attr( $dom_id . '-description' ); ?>"><?php echo esc_html( $text ); ?></p>
					<?php endif; ?>
					<?php if ( ! $editable ) : ?>
						<p class="description sc-wpun-group-readonly"><?php esc_html_e( 'You can see these settings but you are not allowed to change them.', 'wp-updates-notifier' ); ?></p>
					<?php endif; ?>
					<?php $this->put_table( $buckets[''] ?? array() ); ?>
				</fieldset>
			<?php endif; ?>
			<?php $this->render_field_groups( $id, $group, $buckets, $errors ); ?>
		</div>
		<?php
	}

	/**
	 * The rows of the fields of a group, by field group (an empty key for the fields without one).
	 *
	 * @param string               $id        Group id.
	 * @param array<string, mixed> $group     The group.
	 * @param array<string, mixed> $submitted The values of the last failed save.
	 * @param array<string,string> $errors    Messages by field name.
	 * @param bool                 $editable  Whether the user may save the group.
	 *
	 * @return array<string, array<string, string>> Escaped markup of the rows, by field group and field name.
	 */
	private function rows( string $id, array $group, array $submitted, array $errors, bool $editable ): array {
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
			$control     = Form_Parts::control( $schema, self::POST_KEY . '[' . $id . '][' . $name . ']', $dom_id, $value, $errors[ $name ] ?? '', $entry['source'], Form_Parts::applies_note( $name, $schema, $fields ), ! $editable );

			$buckets[ $field_group ][ $name ] = Form_Parts::field_row( $name, $schema, $dom_id, $control );
		}

		return $buckets;
	}

	/**
	 * Output each field group of a group that has fields, as a panel of its own after the group's panel.
	 *
	 * @param string                               $id      Group id.
	 * @param array<string, mixed>                 $group   The group.
	 * @param array<string, array<string, string>> $buckets The rows, from rows().
	 * @param array<string,string>                 $errors  Messages by field name.
	 *
	 * @return void
	 */
	private function render_field_groups( string $id, array $group, array $buckets, array $errors ): void {
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
	 * Check the posted values of the groups on a tab, without saving anything.
	 *
	 * Only the groups the user may save are looked at; the posted values of the others are ignored.
	 *
	 * @param string               $tab    Tab slug.
	 * @param array<string, mixed> $posted The posted lists of values, by group id.
	 *
	 * @return array{valid: array<string, array<string, mixed>>, values: array<string, array<string, mixed>>, errors: array<string, string>} The values to store by group (stored values included), the posted values without secrets by group, and the messages by `<group>/<field>`.
	 */
	public function check( string $tab, array $posted ): array {
		$checked = array(
			'valid'  => array(),
			'values' => array(),
			'errors' => array(),
		);
		foreach ( $this->groups->all() as $id => $group ) {
			if ( $tab !== $group['tab'] || ! $this->may_save( $group ) ) {
				continue;
			}

			$id     = (string) $id;
			$stored = $this->groups->stored( $id );
			$list   = isset( $posted[ $id ] ) && is_array( $posted[ $id ] ) ? $posted[ $id ] : array();
			$errors = array();
			$clean  = $this->validate( $id, $group, $list, $stored, $errors );

			$checked['values'][ $id ] = Form_Parts::without_secrets( $list, $group['fields'] );
			foreach ( $errors as $field => $message ) {
				$checked['errors'][ $id . '/' . $field ] = $message;
			}
			if ( array() === $errors ) {
				$checked['valid'][ $id ] = array_merge( $stored, $clean );
			}
		}

		return $checked;
	}

	/**
	 * Store the groups that passed check().
	 *
	 * @param array<string, array<string, mixed>> $valid The values to store by group id.
	 *
	 * @return void
	 */
	public function save( array $valid ): void {
		foreach ( $valid as $id => $values ) {
			$id  = (string) $id;
			$old = $this->groups->stored( $id );
			$this->groups->save( $id, $values );

			/**
			 * Fires after a settings group was saved with the settings form, with values that passed the checks.
			 *
			 * Both lists hold the stored values only (never a value supplied from a constant, an environment variable or a source),
			 * as they are in the option `sc_wpun_settings_<group>`. It fires once for each group the user could save on the tab,
			 * and also when nothing changed. Nothing is saved, and it does not fire, when any value on the tab is not valid.
			 * Secrets are in the lists: don't log them.
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
			do_action( 'sc_wpun_settings_group_saved', $id, $this->groups->stored( $id ), $old );
		}
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
