/**
 * Smart Auto Upload Images Admin Interface
 *
 * @package SmartAutoUploadImages
 */

import { createRoot, render } from '@wordpress/element';
import { BrowserRouter } from 'react-router';
import SettingsApp from './components/SettingsApp';

/**
 * Initialize the admin interface
 */
function initializeAdmin() {
	const rootElement = document.getElementById('smart-aui-admin-root');

	if (!rootElement) {
		return;
	}

	const app = (
		<BrowserRouter>
			<SettingsApp />
		</BrowserRouter>
	);

	if (typeof createRoot === 'function') {
		const root = createRoot(rootElement);
		root.render(app);
	} else {
		render(app, rootElement);
	}
}

document.addEventListener('DOMContentLoaded', initializeAdmin);
