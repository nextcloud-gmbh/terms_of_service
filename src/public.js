/*
 * Nextcloud - Terms of Service
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2019, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

import Vue from 'vue'
import { getCurrentUser } from '@nextcloud/auth'
import UserApp from './UserApp.vue'

Vue.prototype.t = t
Vue.prototype.n = n
Vue.prototype.OC = OC
Vue.prototype.OCA = OCA

const isNotLoggedIn = getCurrentUser() === null
const isPasswordProtected = (document.getElementById('password-submit') !== null)

if (isNotLoggedIn && !isPasswordProtected) {
	const tofc = document.createElement('div')
	tofc.id = 'terms_of_service_confirm'
	document.body.insertAdjacentElement('afterbegin', tofc)

	// eslint-disable-next-line
	new Vue({
		el: '#terms_of_service_confirm',
		data: {
			source: 'public',
		},
		render: h => h(UserApp),
	})
}
