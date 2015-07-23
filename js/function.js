/*  Copyright 2015  Scott Cariss  (email : scott@l3rady.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

jQuery(function ($) {
	var
		sc_wpun_settings_cron = $('select[name="sc_wpun_settings[cron_method]"]'),
		sc_wpun_settings_interval = $('select[name="sc_wpun_settings[frequency]"]')
		;

	sc_wpun_settings_cron.change(function () {
		if (sc_wpun_settings_cron.val() == "wordpress") {
			sc_wpun_settings_cron.parent().find("div").hide();
			sc_wpun_settings_interval.parent().parent().show();
		} else {
			sc_wpun_settings_cron.parent().find("div").show();
			sc_wpun_settings_interval.parent().parent().hide();
		}
	}).trigger("change");
});