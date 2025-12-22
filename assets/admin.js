(function($) {
	'use strict';

	const WCSMigrator = {
		apiUrl: '',
		nonce: '',
		statusInterval: null,

		init: function() {
			this.apiUrl = wcsSubliumMigrator.apiUrl;
			this.nonce = wcsSubliumMigrator.nonce;

			this.loadFeasibility();
			this.bindEvents();
		},

		bindEvents: function() {
			$(document).on('click', '.wcs-migrator-start', this.handleStartMigration.bind(this));
			$(document).on('click', '.wcs-migrator-pause', this.handlePauseMigration.bind(this));
			$(document).on('click', '.wcs-migrator-resume', this.handleResumeMigration.bind(this));
			$(document).on('click', '.wcs-migrator-cancel', this.handleCancelMigration.bind(this));
		},

		loadFeasibility: function() {
			$.ajax({
				url: this.apiUrl + 'discovery',
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', WCSMigrator.nonce);
				},
				success: function(response) {
					WCSMigrator.renderFeasibility(response);
					WCSMigrator.checkMigrationStatus();
				},
				error: function() {
					WCSMigrator.showError('Failed to load feasibility data');
				}
			});
		},

		checkMigrationStatus: function() {
			$.ajax({
				url: this.apiUrl + 'status',
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', WCSMigrator.nonce);
				},
				success: function(response) {
					if (response.status !== 'idle' && response.status !== 'completed') {
						WCSMigrator.renderProgress(response);
						WCSMigrator.startStatusPolling();
					} else if (response.status === 'completed') {
						WCSMigrator.renderCompleted(response);
					}
				},
				error: function() {
					WCSMigrator.showError('Failed to load migration status');
				}
			});
		},

		startStatusPolling: function() {
			if (this.statusInterval) {
				clearInterval(this.statusInterval);
			}

			this.statusInterval = setInterval(function() {
				WCSMigrator.checkMigrationStatus();
			}, 5000); // Poll every 5 seconds
		},

		stopStatusPolling: function() {
			if (this.statusInterval) {
				clearInterval(this.statusInterval);
				this.statusInterval = null;
			}
		},

		renderFeasibility: function(data) {
			const html = `
				<div class="wcs-migrator-feasibility">
					<h2>Migration Feasibility Analysis</h2>

					<div class="wcs-migrator-readiness ${data.readiness.status}">
						<strong>Status:</strong> ${data.readiness.message}
					</div>

					<div class="wcs-migrator-stats">
						<div class="wcs-migrator-stat-card">
							<h3>Active Subscriptions</h3>
							<p class="stat-value">${data.active_subscriptions}</p>
						</div>
						<div class="wcs-migrator-stat-card">
							<h3>Simple Products</h3>
							<p class="stat-value">${data.products.simple_count}</p>
						</div>
						<div class="wcs-migrator-stat-card">
							<h3>Variable Products</h3>
							<p class="stat-value">${data.products.variable_count}</p>
						</div>
						${data.products.wcsatt_active ? `
						<div class="wcs-migrator-stat-card">
							<h3>WCS_ATT Products</h3>
							<p class="stat-value">${data.products.wcsatt_count}</p>
						</div>
						` : ''}
					</div>

					<div class="wcs-migrator-gateways">
						<h3>Payment Gateways</h3>
						<table>
							<thead>
								<tr>
									<th>Gateway</th>
									<th>Subscriptions</th>
									<th>Manual Renewals</th>
									<th>Status</th>
								</tr>
							</thead>
							<tbody>
								${data.gateways.map(gateway => `
									<tr>
										<td>${gateway.gateway_title}</td>
										<td>${gateway.subscription_count}</td>
										<td>${gateway.manual_renewal_count}</td>
										<td>
											<span class="wcs-migrator-status ${gateway.compatible ? 'compatible' : 'incompatible'}">
												${gateway.compatible ? 'Compatible' : 'Incompatible'}
											</span>
										</td>
									</tr>
								`).join('')}
							</tbody>
						</table>
					</div>

					<div class="wcs-migrator-actions">
						<button class="wcs-migrator-button wcs-migrator-button-primary wcs-migrator-start"
								${data.readiness.status === 'blocked' ? 'disabled' : ''}>
							${wcsSubliumMigrator.strings.startMigration}
						</button>
					</div>
				</div>
			`;

			$('#wcs-sublium-migrator-app').html(html);
		},

		renderProgress: function(data) {
			const productsProgress = data.progress.products || 0;
			const subscriptionsProgress = data.progress.subscriptions || 0;

			const html = `
				<div class="wcs-migrator-feasibility">
					<h2>Migration Progress</h2>

					<div class="wcs-migrator-progress">
						<div class="wcs-migrator-progress-label">
							Products Migration: ${data.products_migration.processed_products} / ${data.products_migration.total_products}
						</div>
						<div class="wcs-migrator-progress-bar">
							<div class="wcs-migrator-progress-fill" style="width: ${productsProgress}%">
								${Math.round(productsProgress)}%
							</div>
						</div>
					</div>

					<div class="wcs-migrator-progress">
						<div class="wcs-migrator-progress-label">
							Subscriptions Migration: ${data.subscriptions_migration.processed_subscriptions} / ${data.subscriptions_migration.total_subscriptions}
						</div>
						<div class="wcs-migrator-progress-bar">
							<div class="wcs-migrator-progress-fill" style="width: ${subscriptionsProgress}%">
								${Math.round(subscriptionsProgress)}%
							</div>
						</div>
					</div>

					${data.errors && data.errors.length > 0 ? `
						<div class="wcs-migrator-errors">
							<h3>Errors (${data.errors.length})</h3>
							<ul>
								${data.errors.slice(-5).map(error => `<li>${error.message} (${error.time})</li>`).join('')}
							</ul>
						</div>
					` : ''}

					<div class="wcs-migrator-actions">
						${data.status === 'paused' ? `
							<button class="wcs-migrator-button wcs-migrator-button-primary wcs-migrator-resume">
								${wcsSubliumMigrator.strings.resumeMigration}
							</button>
						` : `
							<button class="wcs-migrator-button wcs-migrator-button-secondary wcs-migrator-pause">
								${wcsSubliumMigrator.strings.pauseMigration}
							</button>
						`}
						<button class="wcs-migrator-button wcs-migrator-button-secondary wcs-migrator-cancel">
							${wcsSubliumMigrator.strings.cancelMigration}
						</button>
					</div>
				</div>
			`;

			$('#wcs-sublium-migrator-app').html(html);
		},

		renderCompleted: function(data) {
			const html = `
				<div class="wcs-migrator-feasibility">
					<h2>Migration Completed</h2>
					<p>Products migrated: ${data.products_migration.created_plans}</p>
					<p>Subscriptions migrated: ${data.subscriptions_migration.created_subscriptions}</p>
					${data.errors && data.errors.length > 0 ? `
						<div class="wcs-migrator-errors">
							<h3>Errors (${data.errors.length})</h3>
							<ul>
								${data.errors.map(error => `<li>${error.message} (${error.time})</li>`).join('')}
							</ul>
						</div>
					` : ''}
				</div>
			`;

			$('#wcs-sublium-migrator-app').html(html);
			this.stopStatusPolling();
		},

		handleStartMigration: function(e) {
			e.preventDefault();
			if (!confirm('Are you sure you want to start the migration? This will process all products and subscriptions in the background.')) {
				return;
			}

			$.ajax({
				url: this.apiUrl + 'start',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', WCSMigrator.nonce);
				},
				success: function(response) {
					if (response.success) {
						WCSMigrator.checkMigrationStatus();
					} else {
						WCSMigrator.showError(response.message);
					}
				},
				error: function() {
					WCSMigrator.showError('Failed to start migration');
				}
			});
		},

		handlePauseMigration: function(e) {
			e.preventDefault();
			$.ajax({
				url: this.apiUrl + 'pause',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', WCSMigrator.nonce);
				},
				success: function() {
					WCSMigrator.checkMigrationStatus();
				},
				error: function() {
					WCSMigrator.showError('Failed to pause migration');
				}
			});
		},

		handleResumeMigration: function(e) {
			e.preventDefault();
			$.ajax({
				url: this.apiUrl + 'resume',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', WCSMigrator.nonce);
				},
				success: function() {
					WCSMigrator.checkMigrationStatus();
				},
				error: function() {
					WCSMigrator.showError('Failed to resume migration');
				}
			});
		},

		handleCancelMigration: function(e) {
			e.preventDefault();
			if (!confirm('Are you sure you want to cancel the migration? This will stop all background processing.')) {
				return;
			}

			$.ajax({
				url: this.apiUrl + 'cancel',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', WCSMigrator.nonce);
				},
				success: function() {
					WCSMigrator.stopStatusPolling();
					WCSMigrator.loadFeasibility();
				},
				error: function() {
					WCSMigrator.showError('Failed to cancel migration');
				}
			});
		},

		showError: function(message) {
			alert(message);
		}
	};

	$(document).ready(function() {
		WCSMigrator.init();
	});

})(jQuery);
