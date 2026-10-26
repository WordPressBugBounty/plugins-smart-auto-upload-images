/**
 * Recent import history and recovery controls.
 *
 * @package SmartAutoUploadImages
 */

import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

const HistoryPanel = ({ onHistoryLoaded }) => {
	const [history, setHistory] = useState([]);
	const [isLoading, setIsLoading] = useState(true);
	const [recoveringId, setRecoveringId] = useState('');
	const [message, setMessage] = useState('');
	const [error, setError] = useState('');

	const loadHistory = async () => {
		setIsLoading(true);
		setError('');
		try {
			const response = await apiFetch({
				path: '/smart-aui/v1/imports/history',
			});
			setHistory(response.history);
			onHistoryLoaded(response.history.length);
		} catch (apiError) {
			setError(
				apiError?.message ||
					__('Could not load import history.', 'smart-auto-upload-images'),
			);
		} finally {
			setIsLoading(false);
		}
	};

	useEffect(() => {
		loadHistory();
	}, []); // eslint-disable-line react-hooks/exhaustive-deps

	const recoverImport = async (event) => {
		if (
			// eslint-disable-next-line no-alert
			!window.confirm(
				__(
					'Restore the original image URLs in this post? Imported files will remain in the Media Library.',
					'smart-auto-upload-images',
				),
			)
		) {
			return;
		}

		setRecoveringId(event.id);
		setError('');
		setMessage('');
		try {
			const response = await apiFetch({
				path: '/smart-aui/v1/imports/recover',
				method: 'POST',
				data: { event_id: event.id },
			});
			setHistory((current) =>
				current.map((item) =>
					item.id === event.id ? { ...item, recovered_at: response.recovered_at } : item,
				),
			);
			setMessage(
				sprintf(
					__(
						'Restored %d original image URL(s). Imported files remain in the Media Library.',
						'smart-auto-upload-images',
					),
					response.restored_count,
				),
			);
		} catch (apiError) {
			setError(
				apiError?.message ||
					__('Could not restore the original URLs.', 'smart-auto-upload-images'),
			);
		} finally {
			setRecoveringId('');
		}
	};

	const formatDate = (date) =>
		date ? new Date(`${date.replace(' ', 'T')}Z`).toLocaleString() : '';

	return (
		<section className="smart-aui-history">
			<div className="smart-aui-history__header">
				<div>
					<h2>{__('Recent import history', 'smart-auto-upload-images')}</h2>
					<p>
						{__(
							'Review successful image imports and restore original URLs that are still present in a post.',
							'smart-auto-upload-images',
						)}
					</p>
				</div>
				<Button
					variant="tertiary"
					onClick={loadHistory}
					disabled={isLoading || recoveringId !== ''}
				>
					{__('Refresh history', 'smart-auto-upload-images')}
				</Button>
			</div>
			{error && (
				<Notice status="error" isDismissible={false}>
					{error}
				</Notice>
			)}
			{message && (
				<Notice status="success" isDismissible={false}>
					{message}
				</Notice>
			)}
			{isLoading && <Spinner />}
			{!isLoading && history.length === 0 && !error && (
				<p>
					{__(
						'No successful image imports have been recorded yet.',
						'smart-auto-upload-images',
					)}
				</p>
			)}
			{history.length > 0 && (
				<ul className="smart-aui-history__list">
					{history.map((event) => (
						<li key={event.id} className="smart-aui-history__item">
							<div className="smart-aui-history__event-header">
								<strong>
									{event.edit_url ? (
										<a href={event.edit_url}>
											{event.post_title ||
												__('(no title)', 'smart-auto-upload-images')}
										</a>
									) : (
										event.post_title ||
										__('(no title)', 'smart-auto-upload-images')
									)}
								</strong>
								<time dateTime={`${event.imported_at.replace(' ', 'T')}Z`}>
									{formatDate(event.imported_at)}
								</time>
							</div>
							<p className="description">
								{sprintf(
									__('Imported %d image URL(s)', 'smart-auto-upload-images'),
									event.image_count,
								)}
								{event.recovered_at &&
									` · ${__('Recovered', 'smart-auto-upload-images')} ${formatDate(event.recovered_at)}`}
							</p>
							<ul className="smart-aui-history__urls">
								{event.mappings.slice(0, 5).map((mapping) => (
									<li key={`${mapping.original_url}-${mapping.imported_url}`}>
										<code>{mapping.original_url}</code> &rarr;{' '}
										<code>{mapping.imported_url}</code>
									</li>
								))}
								{event.image_count > 5 && (
									<li>
										{sprintf(
											__('And %d more URL(s)', 'smart-auto-upload-images'),
											event.image_count - 5,
										)}
									</li>
								)}
							</ul>
							{!event.recovered_at && (
								<Button
									variant="secondary"
									onClick={() => recoverImport(event)}
									disabled={recoveringId !== ''}
									isBusy={recoveringId === event.id}
								>
									{__('Restore original URLs', 'smart-auto-upload-images')}
								</Button>
							)}
						</li>
					))}
				</ul>
			)}
		</section>
	);
};

export default HistoryPanel;
