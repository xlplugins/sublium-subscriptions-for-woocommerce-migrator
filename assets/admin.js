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
			$(document).on('click', '.wcs-migrator-start-products', this.handleStartProductsMigration.bind(this));
			$(document).on('click', '.wcs-migrator-start-subscriptions', this.handleStartSubscriptionsMigration.bind(this));
			$(document).on('click', '.wcs-migrator-pause', this.handlePauseMigration.bind(this));
			$(document).on('click', '.wcs-migrator-resume', this.handleResumeMigration.bind(this));
			$(document).on('click', '.wcs-migrator-cancel', this.handleCancelMigration.bind(this));
			$(document).on('click', '.wcs-migrator-reset', this.handleResetMigration.bind(this));
		},

		loadFeasibility: function() {
			const self = this;
			// Load both discovery and status to determine button states
			$.when(
				$.ajax({
					url: this.apiUrl + 'discovery',
					method: 'GET',
					beforeSend: function(xhr) {
						xhr.setRequestHeader('X-WP-Nonce', WCSMigrator.nonce);
					}
				}),
				$.ajax({
					url: this.apiUrl + 'status',
					method: 'GET',
					beforeSend: function(xhr) {
						xhr.setRequestHeader('X-WP-Nonce', WCSMigrator.nonce);
					}
				})
			).done(function(discoveryResponse, statusResponse) {
				const discoveryData = discoveryResponse[0];
				const statusData = statusResponse[0];

				// Store discovery data for later use
				WCSMigrator.discoveryData = discoveryData;

				// Check if migration is in progress
				if (statusData.status === 'products_migrating' || statusData.status === 'subscriptions_migrating' || statusData.status === 'paused') {
					WCSMigrator.renderProgress(statusData);
					WCSMigrator.startStatusPolling();
				} else if (statusData.status === 'completed') {
					WCSMigrator.renderCompleted(statusData);
				} else {
					WCSMigrator.renderFeasibility(discoveryData, statusData);
				}
			}).fail(function() {
				WCSMigrator.showError('Failed to load feasibility data');
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
					if (response.status === 'products_migrating' || response.status === 'subscriptions_migrating' || response.status === 'paused') {
						WCSMigrator.renderProgress(response);
						WCSMigrator.startStatusPolling();
					} else if (response.status === 'completed') {
						WCSMigrator.renderCompleted(response);
					} else {
						// Status is idle - reload feasibility to update button states
						WCSMigrator.loadFeasibility();
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

		renderFeasibility: function(data, statusData) {
			// Check migration status to determine button states
			let productsDisabled = data.readiness.status === 'blocked';
			let subscriptionsDisabled = data.readiness.status === 'blocked';
			let productsButtonText = wcsSubliumMigrator.strings.startProductsMigration || 'Migrate Products';
			let subscriptionsButtonText = wcsSubliumMigrator.strings.startSubscriptionsMigration || 'Migrate Subscriptions';

			// Use status data if provided
			if (statusData && statusData.products_migration) {
				const productsCompleted = statusData.products_migration.processed_products >= statusData.products_migration.total_products;
				const productsInProgress = statusData.status === 'products_migrating';
				const subscriptionsCompleted = statusData.subscriptions_migration &&
					statusData.subscriptions_migration.processed_subscriptions >= statusData.subscriptions_migration.total_subscriptions;
				const subscriptionsInProgress = statusData.status === 'subscriptions_migrating';

				// Disable subscriptions button if products not completed
				if (!productsCompleted && statusData.products_migration.total_products > 0) {
					subscriptionsDisabled = true;
				}

				// Disable products button if already completed or in progress
				if (productsCompleted) {
					productsDisabled = true;
					productsButtonText = 'Products Migration Completed';
				} else if (productsInProgress) {
					productsDisabled = true;
					productsButtonText = 'Products Migration In Progress...';
				}

				// Update subscriptions button text
				if (subscriptionsCompleted) {
					subscriptionsDisabled = true;
					subscriptionsButtonText = 'Subscriptions Migration Completed';
				} else if (subscriptionsInProgress) {
					subscriptionsDisabled = true;
					subscriptionsButtonText = 'Subscriptions Migration In Progress...';
				}
			}

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
									<th>Status</th>
								</tr>
							</thead>
							<tbody>
								${data.gateways.map(gateway => `
									<tr>
										<td>${gateway.gateway_title}</td>
										<td>${gateway.subscription_count}</td>
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

					${statusData && statusData.errors && Array.isArray(statusData.errors) && statusData.errors.length > 0 ? `
						<div class="wcs-migrator-errors">
							<h3>Previous Migration Errors (${statusData.errors.length})</h3>
							<ul>
								${statusData.errors.slice(-5).map(error => `<li>${error.message || 'Unknown error'} (${error.time || 'Unknown time'})</li>`).join('')}
							</ul>
						</div>
					` : ''}

					<div class="wcs-migrator-actions">
						<button class="wcs-migrator-button wcs-migrator-button-primary wcs-migrator-start-products"
								${productsDisabled ? 'disabled' : ''}
								title="${productsDisabled ? 'Products migration is already completed or in progress' : 'Start migrating products to create plans'}">
							${productsButtonText}
						</button>
						<button class="wcs-migrator-button wcs-migrator-button-primary wcs-migrator-start-subscriptions"
								${subscriptionsDisabled ? 'disabled' : ''}
								style="margin-left: 10px;"
								title="${subscriptionsDisabled ? 'Please complete products migration first' : 'Start migrating subscriptions'}">
							${subscriptionsButtonText}
						</button>
						${statusData && statusData.products_migration && (
							(statusData.products_migration.processed_products > 0) ||
							(statusData.errors && Array.isArray(statusData.errors) && statusData.errors.length > 0)
						) ? `
							<button class="wcs-migrator-button wcs-migrator-button-secondary wcs-migrator-reset"
									style="margin-left: 10px;"
									title="Reset migration state and start fresh">
								${wcsSubliumMigrator.strings.resetMigration || 'Reset Migration'}
							</button>
						` : ''}
					</div>
				</div>
			`;

			$('#wcs-sublium-migrator-app').html(html);
		},

		renderProgress: function(data) {
			const productsProgress = (data.progress && data.progress.products) ? data.progress.products : 0;
			const subscriptionsProgress = (data.progress && data.progress.subscriptions) ? data.progress.subscriptions : 0;

			// Safely get products migration data
			const productsMigration = data.products_migration || {};
			const productsProcessed = productsMigration.processed_products || 0;
			const productsTotal = productsMigration.total_products || 0;
			const productsCreated = productsMigration.created_plans || 0;
			const productsFailed = productsMigration.failed_products || 0;

			// Safely get subscriptions migration data
			const subscriptionsMigration = data.subscriptions_migration || {};
			const subscriptionsProcessed = subscriptionsMigration.processed_subscriptions || 0;
			const subscriptionsTotal = subscriptionsMigration.total_subscriptions || 0;
			const subscriptionsCreated = subscriptionsMigration.created_subscriptions || 0;
			const subscriptionsFailed = subscriptionsMigration.failed_subscriptions || 0;

			// Determine which migration is active
			const isProductsActive = data.status === 'products_migrating';
			const isSubscriptionsActive = data.status === 'subscriptions_migrating';
			const isPaused = data.status === 'paused';

			const html = `
				<div class="wcs-migrator-feasibility">
					<h2>Migration Progress</h2>

					${(productsTotal > 0 || isProductsActive) ? `
						<div class="wcs-migrator-progress">
							<div class="wcs-migrator-progress-label">
								Products Migration: ${productsProcessed} / ${productsTotal}
								${productsCreated > 0 ? ` (${productsCreated} plans created)` : ''}
								${productsFailed > 0 ? ` (${productsFailed} failed)` : ''}
							</div>
							<div class="wcs-migrator-progress-bar">
								<div class="wcs-migrator-progress-fill" style="width: ${productsProgress}%">
									${Math.round(productsProgress)}%
								</div>
							</div>
						</div>
					` : ''}

					${(subscriptionsTotal > 0 || isSubscriptionsActive) ? `
						<div class="wcs-migrator-progress">
							<div class="wcs-migrator-progress-label">
								Subscriptions Migration: ${subscriptionsProcessed} / ${subscriptionsTotal}
								${subscriptionsCreated > 0 ? ` (${subscriptionsCreated} subscriptions created)` : ''}
								${subscriptionsFailed > 0 ? ` (${subscriptionsFailed} failed)` : ''}
							</div>
							<div class="wcs-migrator-progress-bar">
								<div class="wcs-migrator-progress-fill" style="width: ${subscriptionsProgress}%">
									${Math.round(subscriptionsProgress)}%
								</div>
							</div>
						</div>
					` : ''}

					${data.errors && Array.isArray(data.errors) && data.errors.length > 0 ? `
						<div class="wcs-migrator-errors">
							<h3>Errors (${data.errors.length})</h3>
							<ul>
								${data.errors.slice(-5).map(error => `<li>${error.message || 'Unknown error'} (${error.time || 'Unknown time'})</li>`).join('')}
							</ul>
						</div>
					` : ''}

					<div class="wcs-migrator-actions">
						${isPaused ? `
							<button class="wcs-migrator-button wcs-migrator-button-primary wcs-migrator-resume">
								${wcsSubliumMigrator.strings.resumeMigration || 'Resume Migration'}
							</button>
						` : (isProductsActive || isSubscriptionsActive) ? `
							<button class="wcs-migrator-button wcs-migrator-button-secondary wcs-migrator-pause">
								${wcsSubliumMigrator.strings.pauseMigration || 'Pause Migration'}
							</button>
						` : ''}
						<button class="wcs-migrator-button wcs-migrator-button-secondary wcs-migrator-cancel">
							${wcsSubliumMigrator.strings.cancelMigration || 'Cancel Migration'}
						</button>
					</div>
				</div>
			`;

			$('#wcs-sublium-migrator-app').html(html);
		},

		renderCompleted: function(data) {
			// Safe property access with defaults.
			const productsMigration = data.products_migration || {};
			const subscriptionsMigration = data.subscriptions_migration || {};
			const productsCreated = productsMigration.created_plans || 0;
			const subscriptionsCreated = subscriptionsMigration.created_subscriptions || 0;
			const errors = data.errors || [];

			const html = `
				<div class="wcs-migrator-feasibility">
					<h2>Migration Completed</h2>
					<p>Products migrated: ${productsCreated}</p>
					<p>Subscriptions migrated: ${subscriptionsCreated}</p>
					${errors.length > 0 ? `
						<div class="wcs-migrator-errors">
							<h3>Errors (${errors.length})</h3>
							<ul>
								${errors.map(error => `<li>${error.message} (${error.time})</li>`).join('')}
							</ul>
						</div>
					` : ''}
					<div class="wcs-migrator-actions">
						<button class="wcs-migrator-button wcs-migrator-button-secondary wcs-migrator-reset">
							${wcsSubliumMigrator.strings.resetMigration || 'Reset Migration'}
						</button>
					</div>
				</div>
			`;

			$('#wcs-sublium-migrator-app').html(html);
			this.stopStatusPolling();
		},

		handleStartProductsMigration: function(e) {
			e.preventDefault();
			if (!confirm('Are you sure you want to start products migration? This will process all subscription products and create plans in the background.')) {
				return;
			}

			$.ajax({
				url: this.apiUrl + 'start-products',
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
					WCSMigrator.showError('Failed to start products migration');
				}
			});
		},

		handleStartSubscriptionsMigration: function(e) {
			e.preventDefault();

			// Check gateway compatibility if discovery data is available
			if (this.discoveryData && this.discoveryData.readiness) {
				const readinessStatus = this.discoveryData.readiness.status;
				
				// If readiness is "partial", show detailed warning
				if (readinessStatus === 'partial') {
					const incompatibleGateways = this.discoveryData.gateways.filter(g => !g.compatible);
					let warningMessage = '⚠️ WARNING: Some payment gateways are not compatible with Sublium.\n\n';
					warningMessage += 'Incompatible Gateways:\n';
					incompatibleGateways.forEach(gateway => {
						warningMessage += `• ${gateway.gateway_title} (${gateway.gateway_id}) - ${gateway.subscription_count} subscription(s)\n`;
						warningMessage += `  ${gateway.message}\n\n`;
					});
					warningMessage += 'Subscriptions using these gateways will be migrated but may require manual payment method updates.\n\n';
					warningMessage += 'Do you want to proceed with the migration?';

					if (!confirm(warningMessage)) {
						return;
					}
				} else if (readinessStatus === 'blocked') {
					alert('Migration is blocked. Please resolve the issues before proceeding.');
					return;
				}
			}

			// Standard confirmation
			if (!confirm('Are you sure you want to start subscriptions migration? This will process all subscriptions in the background. Make sure products migration is completed first.')) {
				return;
			}

			$.ajax({
				url: this.apiUrl + 'start-subscriptions',
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
					WCSMigrator.showError('Failed to start subscriptions migration');
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

		handleResetMigration: function(e) {
			e.preventDefault();
			if (!confirm('Are you sure you want to reset the migration state? This will clear all progress and errors, allowing you to start fresh.')) {
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
					WCSMigrator.showSuccess('Migration state has been reset. You can now start a fresh migration.');
				},
				error: function() {
					WCSMigrator.showError('Failed to reset migration state');
				}
			});
		},

		showError: function(message) {
			alert(message);
		},

		showSuccess: function(message) {
			// Create a temporary success message element
			const $message = $('<div class="wcs-migrator-success notice notice-success is-dismissible" style="padding: 10px; margin: 10px 0;"><p>' + message + '</p></div>');
			$('#wcs-sublium-migrator-app').prepend($message);
			setTimeout(function() {
				$message.fadeOut(function() {
					$message.remove();
				});
			}, 5000);
		}
	};

	$(document).ready(function() {
		WCSMigrator.init();
	});

})(jQuery);
