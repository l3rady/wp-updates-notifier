<?php
/**
 * Pieces the settings forms share: field rows, notes, frames, error summaries and the round trip of a failed save.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

use WPUN\Boolean_Field;
use WPUN\Field_Types;

/**
 * Static helpers of the channel form and the settings group form. Every method returns escaped markup or plain data.
 *
 * @internal
 */
final class Form_Parts {

	/**
	 * Output the summary of the errors of a failed save.
	 *
	 * @param array<string, string> $errors  Messages by field key.
	 * @param callable              $anchor  Gives the id of the control an error key links to; empty for an error that names no field.
	 * @param string                $message What was not saved, as a sentence.
	 *
	 * @return void
	 */
	public static function error_summary( array $errors, callable $anchor, string $message ): void {
		if ( array() === $errors ) {
			return;
		}
		?>
		<div class="notice notice-error inline sc-wpun-form-errors" role="alert" tabindex="-1">
			<h2><?php esc_html_e( 'There is a problem', 'wp-updates-notifier' ); ?></h2>
			<p><?php echo esc_html( $message ); ?></p>
			<ul>
				<?php foreach ( $errors as $key => $text ) : ?>
					<li>
						<?php $target = (string) call_user_func( $anchor, (string) $key ); ?>
						<?php if ( '' === $target ) : ?>
							<?php echo esc_html( $text ); ?>
						<?php else : ?>
							<a href="#<?php echo esc_attr( $target ); ?>"><?php echo esc_html( $text ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * A row of a form table.
	 *
	 * @param string $title    Text of the row header, already escaped.
	 * @param string $target      Id of the single control the header labels; empty if the control names itself.
	 * @param string $controls Markup of the cell, already escaped.
	 * @param bool   $required Whether to mark the field as required.
	 *
	 * @return string Escaped markup.
	 */
	public static function row( string $title, string $target, string $controls, bool $required = false ): string {
		return '<tr><th scope="row">' . self::title_html( $title, $target, $required ) . '</th><td>' . $controls . '</td></tr>';
	}

	/**
	 * A row for a field of the routing or settings section.
	 *
	 * The header is the field's label, or its name without one, and a label for the control when there is only one.
	 *
	 * @param string               $name     Field name.
	 * @param array<string, mixed> $schema   Field schema.
	 * @param string               $id       Id of the control.
	 * @param string               $controls Markup of the cell, already escaped.
	 *
	 * @return string Escaped markup.
	 */
	public static function field_row( string $name, array $schema, string $id, string $controls ): string {
		return self::row( esc_html( Field_Types::label( $name, $schema ) ), Field_Renderer::labels_one_control( $schema ) ? $id : '', $controls, Field_Renderer::is_required( $schema ) );
	}

	/**
	 * The content of the header cell of a row: the title, as a label of the control if there is a single one.
	 *
	 * @param string $title    Text of the row header, already escaped.
	 * @param string $target   Id of the single control the header labels; empty if the control names itself.
	 * @param bool   $required Whether to mark the field as required.
	 *
	 * @return string Escaped markup.
	 */
	public static function title_html( string $title, string $target, bool $required ): string {
		$title .= $required ? Field_Renderer::required_marker() : '';

		return '' === $target ? $title : '<label for="' . esc_attr( $target ) . '">' . $title . '</label>';
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
	 * @param string               $applies  The note on when the field applies, if it is conditional.
	 * @param bool                 $disabled Whether to disable the control, as for a user who may not change it.
	 *
	 * @return string The markup of the cell.
	 */
	public static function control( array $schema, string $name, string $id, $value, string $error, ?string $external = null, string $applies = '', bool $disabled = false ): string {
		$description = isset( $schema['description'] ) && is_string( $schema['description'] ) && '' !== $schema['description'] ? $schema['description'] : '';
		if ( null !== $external && Field_Types::hides_external( $schema ) ) {
			// A value from outside the database may be a secret the schema forgot to flag: don't print it.
			$value = '';
		}
		// The field adds the id of its error after these.
		$described_by = trim( ( '' !== $description ? $id . '-description' : '' ) . ( null !== $external ? ' ' . $id . '-external' : '' ) . ( '' !== $applies ? ' ' . $id . '-applies-when' : '' ) );
		$html         = Field_Renderer::field( $schema, $name, $id, $value, null !== $external || $disabled, $described_by, array(), $error );
		if ( null !== $external ) {
			// translators: %s: Where the value comes from, for example "constant SC_WPUN_SETTING_FREQUENCY".
			$html .= ' <span class="description sc-wpun-external" id="' . esc_attr( $id . '-external' ) . '">' . esc_html( sprintf( __( 'Set by %s', 'wp-updates-notifier' ), $external ) ) . '</span>';
		}
		if ( '' !== $description ) {
			$html .= '<p class="description" id="' . esc_attr( $id . '-description' ) . '">' . esc_html( $description ) . '</p>';
		}
		if ( '' !== $applies ) {
			$html .= '<p class="description sc-wpun-applies-when" id="' . esc_attr( $id . '-applies-when' ) . '">' . esc_html( $applies ) . '</p>';
		}

		return $html;
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
	public static function first_control_id( array $schema, string $id ): string {
		$single_checkbox = 'boolean' === Field_Types::canonical( $schema['type'] ?? null ) && 'checkbox' === Boolean_Field::normalise( $schema )['display'];

		return $single_checkbox || Field_Renderer::labels_one_control( $schema ) ? $id : $id . '_0';
	}

	/**
	 * The opening markup of a field group or a panel: a fieldset with a legend, in a details element when collapsed.
	 *
	 * @param string $dom_id    Base of the DOM ids.
	 * @param string $label     Title.
	 * @param string $text      Help text, or an empty string.
	 * @param bool   $collapsed Whether it is folded into a details element.
	 * @param bool   $open      Whether the details element starts open.
	 * @param bool   $is_panel  Whether it is a panel of the module, not a group of fields: it also gets the panel classes.
	 *
	 * @return string Escaped markup.
	 */
	public static function frame_open( string $dom_id, string $label, string $text, bool $collapsed, bool $open, bool $is_panel ): string {
		$html = '';
		if ( $collapsed ) {
			$html .= '<details class="sc-wpun-field-group-details' . ( $is_panel ? ' sc-wpun-form-panel-details' : '' ) . '"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html( $label ) . '</summary>';
		}
		$html .= '<fieldset class="sc-wpun-field-group' . ( $is_panel ? ' sc-wpun-form-panel' : '' ) . '"' . ( '' !== $text ? ' aria-describedby="' . esc_attr( $dom_id . '-description' ) . '"' : '' ) . '>';
		$html .= $collapsed ? '<legend class="screen-reader-text">' . esc_html( $label ) . '</legend>' : '<legend><h3>' . esc_html( $label ) . '</h3></legend>';
		if ( '' !== $text ) {
			$html .= '<p class="description" id="' . esc_attr( $dom_id . '-description' ) . '">' . esc_html( $text ) . '</p>';
		}

		return $html;
	}

	/**
	 * The closing markup that goes with frame_open().
	 *
	 * @param bool $collapsed Whether it was folded into a details element.
	 *
	 * @return string
	 */
	public static function frame_close( bool $collapsed ): string {
		return '</fieldset>' . ( $collapsed ? '</details>' : '' );
	}

	/**
	 * The note on when a conditional field is used.
	 *
	 * @param string                              $name   Name of the field.
	 * @param array<string, mixed>                $schema Field schema.
	 * @param array<string, array<string, mixed>> $fields The fields of the module.
	 *
	 * @return string Plain text, or an empty string for a field without a (usable) condition.
	 */
	public static function applies_note( string $name, array $schema, array $fields ): string {
		$clauses = array();
		foreach ( Field_Types::conditions( $name, $schema, $fields ) as $other => $wanted ) {
			$other_schema = (array) $fields[ $other ];
			$other_label  = Field_Types::label( (string) $other, $other_schema );
			$texts        = array();
			foreach ( $wanted as $value ) {
				$texts[] = self::value_text( $value, $other_schema );
			}
			$clauses[] = sprintf(
				// translators: 1: Label of another field, for example "Mode". 2: The value or values it must have, for example "App" or "App or Bot".
				__( '%1$s is %2$s', 'wp-updates-notifier' ),
				$other_label,
				self::join_texts( $texts, 'or' )
			);
		}
		if ( array() === $clauses ) {
			return '';
		}

		return sprintf(
			// translators: %s: The condition, for example "Mode is App" or "Mode is App and Format is HTML".
			__( 'Used only when %s.', 'wp-updates-notifier' ),
			self::join_texts( $clauses, 'and' )
		);
	}

	/**
	 * The words for a value a field is compared with: the label of an option, the on or off text of a boolean, or the value itself.
	 *
	 * @param mixed                $value  The value in the condition.
	 * @param array<string, mixed> $schema Schema of the field it is compared with.
	 *
	 * @return string
	 */
	public static function value_text( $value, array $schema ): string {
		$type = Field_Types::canonical( $schema['type'] ?? null );

		if ( 'boolean' === $type && in_array( $value, array( true, 1, '1', false, 0, '0' ), true ) ) {
			$on     = in_array( $value, array( true, 1, '1' ), true );
			$labels = isset( $schema['labels'] ) && is_array( $schema['labels'] ) ? $schema['labels'] : array();
			$label  = $labels[ $on ? 'on' : 'off' ] ?? null;
			if ( is_string( $label ) && '' !== $label ) {
				return $label;
			}

			return $on ? __( 'Yes', 'wp-updates-notifier' ) : __( 'No', 'wp-updates-notifier' );
		}

		$raw = is_scalar( $value ) ? (string) $value : '';
		if ( 'enum' === $type && isset( $schema['options'] ) && is_array( $schema['options'] ) && isset( $schema['options'][ $raw ] ) && is_string( $schema['options'][ $raw ] ) ) {
			return $schema['options'][ $raw ];
		}

		return $raw;
	}

	/**
	 * Join texts as a sentence list: "A", "A or B", "A, B or C".
	 *
	 * @param string[] $texts The texts.
	 * @param string   $word  `or` or `and`.
	 *
	 * @return string
	 */
	public static function join_texts( array $texts, string $word ): string {
		$last = (string) array_pop( $texts );
		if ( array() === $texts ) {
			return $last;
		}

		$head = array_shift( $texts );
		foreach ( $texts as $text ) {
			// translators: 1: The items so far. 2: The next item. Used to join a list such as "A, B or C".
			$head = sprintf( _x( '%1$s, %2$s', 'list of values or conditions', 'wp-updates-notifier' ), $head, $text );
		}

		return 'or' === $word
			// translators: 1: The items so far, for example "A, B". 2: The last item. A list of values of which any is enough.
			? sprintf( __( '%1$s or %2$s', 'wp-updates-notifier' ), $head, $last )
			// translators: 1: The items so far, for example "A, B". 2: The last item. A list of conditions that must all hold.
			: sprintf( __( '%1$s and %2$s', 'wp-updates-notifier' ), $head, $last );
	}

	/**
	 * Read what the last failed save left for the current user, if it is for this form, and delete it when asked to.
	 *
	 * @param string $meta_key Key of the user meta.
	 * @param string $form   `edit:<id>` or `new:<type>`.
	 * @param bool   $delete Whether to delete it: the form that shows it does, the page title only looks.
	 *
	 * @return array{values: array<string, mixed>, errors: array<string, string>}|null Null if there is none, it is for another site or form, or it is stale.
	 */
	public static function round_trip( string $meta_key, string $form, bool $delete ): ?array {
		$user_id = get_current_user_id();
		$stored  = get_user_meta( $user_id, $meta_key, true );
		if ( ! is_array( $stored ) || ( $stored['site'] ?? 0 ) !== get_current_blog_id() ) {
			return null; // None, or it belongs to another site of the network.
		}
		if ( $delete ) {
			delete_user_meta( $user_id, $meta_key );
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
	 * Keep what was submitted and what was wrong for the form to show on the next page load.
	 *
	 * @param string               $meta_key Key of the user meta.
	 * @param string               $form   `edit:<id>` or `new:<type>`.
	 * @param array<string, mixed> $values The submitted values.
	 * @param array<string,string> $errors Messages by field key.
	 *
	 * @return void
	 */
	public static function remember( string $meta_key, string $form, array $values, array $errors ): void {
		update_user_meta(
			get_current_user_id(),
			$meta_key,
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
	 * Add the messages of the errors of a validation to a list keyed by field.
	 *
	 * @param array<string, string>               $errors Messages by field key, added to.
	 * @param mixed                               $result The result of the validation.
	 * @param string                              $area   `label`, `enabled`, `routing` or `settings`; empty to key the errors by the field name alone.
	 * @param array<string, array<string, mixed>> $known  The fields of the area that can have an error; an error that names another has no field.
	 *
	 * @return void
	 */
	public static function collect_errors( array &$errors, $result, string $area, array $known ): void {
		if ( ! $result instanceof \WP_Error ) {
			return;
		}

		foreach ( $result->get_error_codes() as $code ) {
			$data  = $result->get_error_data( $code );
			$field = is_array( $data ) && isset( $data['field'] ) && is_string( $data['field'] ) ? $data['field'] : '';
			if ( 'label' === $area || 'enabled' === $area ) {
				$key = $area;
			} else {
				$key = '' !== $field && isset( $known[ $field ] ) ? ( '' === $area ? $field : $area . '.' . $field ) : '';
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
	public static function without_secrets( array $settings, array $fields ): array {
		foreach ( array_keys( $settings ) as $name ) {
			$name = (string) $name;
			if ( '__clear' === substr( $name, -7 ) || ( isset( $fields[ $name ] ) && is_array( $fields[ $name ] ) && Field_Types::is_secret( $fields[ $name ] ) ) ) {
				unset( $settings[ $name ] );
			}
		}

		return $settings;
	}

	/**
	 * A list in the posted data, unslashed.
	 *
	 * @param string $key Name in `$_POST`.
	 *
	 * @return array<string, mixed> Empty if missing or not a list.
	 */
	public static function posted_list( string $key ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Called after the nonce is checked; the field types clean the values.
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}
		$list = wp_unslash( $_POST[ $key ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return is_array( $list ) ? $list : array();
	}

	/**
	 * A key of the request as a string, from the posted data, cleaned as a key.
	 *
	 * @param string $key Name in `$_POST`.
	 *
	 * @return string Empty if missing or not a string.
	 */
	public static function posted_key( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only used to find the nonce action.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : '';
	}
}
