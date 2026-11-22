<?php
/**
 * The pieces the settings forms share (#512).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Form_Parts;

/**
 * `Form_Parts::control()` and `Form_Parts::external_note()`.
 */
class Test_Form_Parts extends WPUN_Test_Case {

	/**
	 * The note says where the value comes from, and has an id only when asked for one.
	 */
	public function test_the_external_note_with_and_without_an_id() {
		$this->assertSame(
			' <span class="description sc-wpun-external" id="field-external">Set by constant X_Y</span>',
			Form_Parts::external_note( 'constant X_Y', 'field-external' )
		);
		$this->assertSame(
			' <span class="description sc-wpun-external">Set by constant X_Y</span>',
			Form_Parts::external_note( 'constant X_Y' )
		);
	}

	/**
	 * The source is escaped.
	 */
	public function test_the_external_note_is_escaped() {
		$html = Form_Parts::external_note( '<b>x</b>', '"id' );

		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( 'Set by &lt;b&gt;x&lt;/b&gt;', $html );
		$this->assertStringContainsString( 'id="&quot;id"', $html );
	}

	/**
	 * A control draws the extra choices it is given, and the control uses the note of an external value.
	 */
	public function test_a_control_draws_extra_choices() {
		$schema = array(
			'type'    => 'enum',
			'options' => array(
				'a' => 'Option A',
				'b' => 'Option B',
			),
		);

		$plain = Form_Parts::control( $schema, 'f[x]', 'f_x', 'gone', '' );
		$extra = Form_Parts::control( $schema, 'f[x]', 'f_x', 'gone', '', null, '', false, array( 'gone' => 'gone (no longer available)' ) );

		$this->assertStringNotContainsString( 'no longer available', $plain );
		$this->assertStringContainsString( 'gone (no longer available)', $extra );
		$this->assertMatchesRegularExpression( '#value="gone"[^>]*checked|checked[^>]*value="gone"|value="gone" selected#', $extra, 'The extra choice is the current one.' );
		$this->assertStringContainsString( 'Option A', $extra );
	}

	/**
	 * The note of an external value is part of a control, with the id its control points to.
	 */
	public function test_a_control_has_the_external_note() {
		$schema = array(
			'type'    => 'enum',
			'options' => array( 'a' => 'Option A' ),
		);

		$html = Form_Parts::control( $schema, 'f[x]', 'f_x', 'a', '', 'constant X_Y' );

		$this->assertStringContainsString( Form_Parts::external_note( 'constant X_Y', 'f_x-external' ), $html );
		$this->assertStringContainsString( 'f_x-external', $html );
		$this->assertStringContainsString( 'disabled', $html );
	}
}
