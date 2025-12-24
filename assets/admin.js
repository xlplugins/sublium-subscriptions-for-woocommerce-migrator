(function($) {
	'use strict';

	const WCSMigrator = {
		apiUrl: '',
		nonce: '',
		statusInterval: null,
		discoveryData: null,
		currentStep: 1,
		totalSteps: 5,
		integrationsData: null,
		isNavigating: false, // Flag to prevent auto-step changes during manual navigation

		init: function() {
			this.apiUrl = wcsSubliumMigrator.apiUrl;
			this.nonce = wcsSubliumMigrator.nonce;

			// Bind events first so they're ready when content loads
			this.bindEvents();
			this.loadFeasibility();
		},

		bindEvents: function() {
			// Use event delegation - unbind first to prevent duplicate handlers
			$(document).off('click', '.wcs-wizard-next').on('click', '.wcs-wizard-next', this.handleNextStep.bind(this));
			$(document).off('click', '.wcs-wizard-prev').on('click', '.wcs-wizard-prev', this.handlePrevStep.bind(this));
			$(document).on('click', '.wcs-wizard-step-link', this.handleStepClick.bind(this));
			$(document).on('click', '.wcs-migrator-start-subscriptions', this.handleStartSubscriptionsMigration.bind(this));
			$(document).on('click', '.wcs-migrator-start-products', this.handleStartProductsMigration.bind(this));
			$(document).on('click', '.wcs-migrator-pause', this.handlePauseMigration.bind(this));
			$(document).on('click', '.wcs-migrator-resume', this.handleResumeMigration.bind(this));
			$(document).on('click', '.wcs-migrator-cancel', this.handleCancelMigration.bind(this));
			$(document).on('click', '.wcs-migrator-reset', this.handleResetMigration.bind(this));
			$(document).on('click', '.wcs-migrator-load-products', this.handleLoadProducts.bind(this));
			$(document).on('click', '.wcs-migrator-convert-product', this.handleConvertProduct.bind(this));
			$(document).on('click', '.wcs-wizard-deactivate-wcs', this.handleDeactivateWCS.bind(this));
			$(document).on('click', '.wcs-wizard-deactivate-wcsatt', this.handleDeactivateWCSATT.bind(this));
			$(document).on('click', '.wcs-wizard-convert-all-products', this.handleConvertAllProducts.bind(this));
		},

		loadFeasibility: function() {
			const self = this;
			const integrationsUrl = this.apiUrl.replace('wcs-sublium-migrator/v1/', 'sublium-wcs-admin/v1/integrations?_locale=user');

			// Create a promise that always resolves, even on 404
			const integrationsPromise = $.ajax({
				url: integrationsUrl,
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', self.nonce);
				}
			}).then(
				function(response) {
					// Success - return the response
					return response;
				},
				function(xhr) {
					// Error (including 404) - return empty array structure
					return { success: false, data: { items: [] } };
				}
			);

			$.when(
				$.ajax({
					url: this.apiUrl + 'discovery',
					method: 'GET',
					beforeSend: function(xhr) {
						xhr.setRequestHeader('X-WP-Nonce', self.nonce);
					}
				}),
				$.ajax({
					url: this.apiUrl + 'status',
					method: 'GET',
					beforeSend: function(xhr) {
						xhr.setRequestHeader('X-WP-Nonce', self.nonce);
					}
				}),
				integrationsPromise
			).done(function(discoveryResponse, statusResponse, integrationsResponse) {
				const discoveryData = discoveryResponse[0];
				const statusData = statusResponse[0];

				// Handle integrations response - it might fail if Sublium Pro is not active
				let integrationsData = { success: false, data: { items: [] } };
				// integrationsResponse is already resolved (either success or error fallback)
				if (integrationsResponse && integrationsResponse.success !== false && integrationsResponse.data && integrationsResponse.data.items) {
					integrationsData = integrationsResponse;
				}

				WCSMigrator.discoveryData = discoveryData;
				WCSMigrator.integrationsData = integrationsData.success && integrationsData.data ? integrationsData.data.items : [];

				// Only auto-set step to 5 if migration is completed AND we're not manually navigating
				// Preserve the current step if user is navigating manually
				if (statusData.status === 'products_migrating' || statusData.status === 'subscriptions_migrating' || statusData.status === 'paused') {
					WCSMigrator.renderProgress(statusData);
					WCSMigrator.startStatusPolling();
				} else if (statusData.status === 'completed' && !WCSMigrator.isNavigating && WCSMigrator.currentStep < 5) {
					// Only auto-navigate to step 5 if not manually navigating and not already there
					WCSMigrator.currentStep = 5;
					WCSMigrator.renderWizard(discoveryData, statusData);
				} else {
					// Render with current step (preserves manual navigation)
					WCSMigrator.renderWizard(discoveryData, statusData);
				}

				// Reset navigation flag after rendering
				WCSMigrator.isNavigating = false;
			}).fail(function() {
				WCSMigrator.showError('Failed to load migration data');
			});
		},

		renderWizard: function(discoveryData, statusData) {
			const html = `
				<div class="wcs-wizard">
					<div class="wcs-wizard-steps">
						${this.renderStepIndicator()}
					</div>
					<div class="wcs-wizard-content">
						${this.renderStepContent(discoveryData, statusData)}
					</div>
					<div class="wcs-wizard-actions">
						${this.renderWizardActions(discoveryData, statusData)}
					</div>
				</div>
			`;
			$('#wcs-sublium-migrator-app').html(html);
		},

		renderStepIndicator: function() {
			const steps = [
				{ number: 1, title: 'Health Check', icon: '✓' },
				{ number: 2, title: 'Migrate Subscriptions', icon: '📋' },
				{ number: 3, title: 'Migrate Products', icon: '📦' },
				{ number: 4, title: 'Add-ons', icon: '🔌' },
				{ number: 5, title: 'Go Live', icon: '🚀' }
			];

			return `
				<ul class="wcs-wizard-steps-list">
					${steps.map((step, index) => `
						<li class="wcs-wizard-step ${this.currentStep === step.number ? 'active' : ''} ${this.currentStep > step.number ? 'completed' : ''}">
							<span class="wcs-wizard-step-link wcs-wizard-step-disabled" data-step="${step.number}">
								<span class="wcs-wizard-step-number">${this.currentStep > step.number ? '✓' : step.number}</span>
								<span class="wcs-wizard-step-title">${step.title}</span>
							</span>
						</li>
					`).join('')}
				</ul>
			`;
		},

		renderStepContent: function(discoveryData, statusData) {
			switch(this.currentStep) {
				case 1:
					return this.renderStep1HealthCheck(discoveryData, statusData);
				case 2:
					return this.renderStep2Subscriptions(discoveryData, statusData);
				case 3:
					return this.renderStep3Products(discoveryData, statusData);
				case 4:
					return this.renderStep4Addons(discoveryData, statusData);
				case 5:
					return this.renderStep5GoLive(discoveryData, statusData);
				default:
					return this.renderStep1HealthCheck(discoveryData, statusData);
			}
		},

		renderStep1HealthCheck: function(data, statusData) {
			return `
				<div class="wcs-wizard-step-content">
					<h2>Health Check</h2>
					<p class="wcs-wizard-description">Review your current WooCommerce Subscriptions setup and migration readiness.</p>

					<div class="wcs-migrator-readiness ${data.readiness.status}">
						<strong>Status:</strong> ${data.readiness.message}
					</div>

					<div class="wcs-migrator-stats">
						<div class="wcs-migrator-stat-card">
							<h3>Total Subscriptions</h3>
							<p class="stat-value">${data.active_subscriptions}</p>
							<p class="stat-note">(All statuses will be migrated)</p>
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
							<h3>All product subscription</h3>
							<p class="stat-value">${data.products.wcsatt_count}</p>
						</div>
						` : ''}
					</div>

					${data.subscription_statuses && Object.keys(data.subscription_statuses).length > 0 ? `
					<div class="wcs-migrator-subscription-statuses">
						<h3>Subscriptions by Status</h3>
						<table>
							<thead>
								<tr>
									<th>Status</th>
									<th>Count</th>
								</tr>
							</thead>
							<tbody>
								${Object.entries(data.subscription_statuses).map(([status, count]) => `
									<tr>
										<td>${status.charAt(0).toUpperCase() + status.slice(1).replace(/-/g, ' ')}</td>
										<td>${count}</td>
									</tr>
								`).join('')}
							</tbody>
						</table>
					</div>
					` : ''}

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
				</div>
			`;
		},

		renderStep2Subscriptions: function(data, statusData) {
			const subscriptionsMigration = statusData.subscriptions_migration || {};
			const isCompleted = subscriptionsMigration.processed_subscriptions >= subscriptionsMigration.total_subscriptions && subscriptionsMigration.total_subscriptions > 0;
			const isInProgress = statusData.status === 'subscriptions_migrating';
			const isPaused = statusData.status === 'paused';

			return `
				<div class="wcs-wizard-step-content">
					<h2>Migrate Subscriptions</h2>
					<p class="wcs-wizard-description">Migrate all WooCommerce Subscriptions to Sublium. Subscriptions are grouped by payment gateway.</p>

					<div class="wcs-migrator-gateways">
						<h3>Subscriptions by Payment Gateway</h3>
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

					${isInProgress || isPaused ? `
						<div class="wcs-migrator-progress">
							<div class="wcs-migrator-progress-label">
								Subscriptions Migration: ${subscriptionsMigration.processed_subscriptions || 0} / ${subscriptionsMigration.total_subscriptions || 0}
								${subscriptionsMigration.created_subscriptions ? ` (${subscriptionsMigration.created_subscriptions} created)` : ''}
							</div>
							<div class="wcs-migrator-progress-bar">
								<div class="wcs-migrator-progress-fill" style="width: ${Math.min(100, ((subscriptionsMigration.processed_subscriptions || 0) / (subscriptionsMigration.total_subscriptions || 1)) * 100)}%">
									${Math.round(Math.min(100, ((subscriptionsMigration.processed_subscriptions || 0) / (subscriptionsMigration.total_subscriptions || 1)) * 100))}%
								</div>
							</div>
						</div>
					` : ''}

					${isCompleted ? `
						<div class="wcs-migrator-success-message">
							<p><strong>✓ Subscriptions migration completed!</strong></p>
							<p>${subscriptionsMigration.created_subscriptions || 0} subscriptions migrated successfully.</p>
						</div>
					` : ''}

					${!isCompleted && !isInProgress && !isPaused ? `
						<div class="wcs-wizard-info-box">
							<p><strong>Ready to migrate subscriptions?</strong></p>
							<p>This will process all ${data.active_subscriptions} subscriptions in the background. The migration can be paused or resumed at any time.</p>
						</div>
					` : ''}
				</div>
			`;
		},

		renderStep3Products: function(data, statusData) {
			const productsMigration = statusData.products_migration || {};
			const isCompleted = productsMigration.processed_products >= productsMigration.total_products && productsMigration.total_products > 0;
			const isInProgress = statusData.status === 'products_migrating';
			const isPaused = statusData.status === 'paused';

			return `
				<div class="wcs-wizard-step-content">
					<h2>Migrate Products</h2>
					<p class="wcs-wizard-description">Migrate subscription products to create plans in Sublium.</p>

					<div class="wcs-migrator-stats">
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
							<h3>All product subscription</h3>
							<p class="stat-value">${data.products.wcsatt_count}</p>
						</div>
						` : ''}
						<div class="wcs-migrator-stat-card">
							<h3>Total Products</h3>
							<p class="stat-value">${data.products.total}</p>
						</div>
					</div>

					${isInProgress || isPaused ? `
						<div class="wcs-migrator-progress">
							<div class="wcs-migrator-progress-label">
								Products Migration: ${productsMigration.processed_products || 0} / ${productsMigration.total_products || 0}
								${productsMigration.created_plans ? ` (${productsMigration.created_plans} plans created)` : ''}
							</div>
							<div class="wcs-migrator-progress-bar">
								<div class="wcs-migrator-progress-fill" style="width: ${((productsMigration.processed_products || 0) / (productsMigration.total_products || 1)) * 100}%">
									${Math.round(((productsMigration.processed_products || 0) / (productsMigration.total_products || 1)) * 100)}%
								</div>
							</div>
						</div>
					` : ''}

					${isCompleted ? `
						<div class="wcs-migrator-success-message">
							<p><strong>✓ Products migration completed!</strong></p>
							<p>${productsMigration.created_plans || 0} plans created successfully.</p>
						</div>
					` : ''}

					${!isCompleted && !isInProgress && !isPaused ? `
						<div class="wcs-wizard-info-box">
							<p><strong>Ready to migrate products?</strong></p>
							<p>This will process all ${data.products.total} subscription products and create plans in Sublium. Products and subscriptions migrations can be run independently.</p>
						</div>
					` : ''}
				</div>
			`;
		},

		renderStep4Addons: function(data, statusData) {
			const integrations = this.integrationsData || [];
			const installedIntegrations = integrations.filter(integration => integration.is_installed);
			const connectedIntegrations = integrations.filter(integration => integration.status);

			return `
				<div class="wcs-wizard-step-content">
					<h2>Add-ons & Integrations</h2>
					<p class="wcs-wizard-description">Review Sublium integrations. This step is optional - you can configure integrations later.</p>

					${integrations.length > 0 ? `
						<div class="wcs-wizard-integrations">
							<p class="wcs-wizard-summary">
								<strong>${integrations.length}</strong> integrations available,
								<strong>${installedIntegrations.length}</strong> installed,
								<strong>${connectedIntegrations.length}</strong> connected
							</p>
							<div class="wcs-wizard-integrations-list">
								${integrations.map(integration => `
									<div class="wcs-wizard-integration-item ${integration.is_installed ? 'installed' : 'not-installed'} ${integration.status ? 'connected' : ''}">
										<div class="wcs-wizard-integration-header">
											<h4>${integration.title || integration.key}</h4>
											<span class="wcs-wizard-integration-status">
												${integration.status ? '<span class="status-connected">✓ Connected</span>' : ''}
												${!integration.is_installed ? '<span class="status-not-installed">Not Installed</span>' : integration.status ? '' : '<span class="status-not-connected">Not Connected</span>'}
											</span>
										</div>
										${integration.description ? `<p class="wcs-wizard-integration-description">${integration.description}</p>` : ''}
									</div>
								`).join('')}
							</div>
						</div>
					` : `
						<div class="wcs-wizard-info-box">
							<p>No integrations found. Integrations are available in Sublium Pro.</p>
						</div>
					`}
				</div>
			`;
		},

		renderStep5GoLive: function(data, statusData) {
			const subscriptionsMigration = statusData.subscriptions_migration || {};
			const productsMigration = statusData.products_migration || {};
			const isMigrationComplete = subscriptionsMigration.processed_subscriptions >= subscriptionsMigration.total_subscriptions &&
										productsMigration.processed_products >= productsMigration.total_products &&
										subscriptionsMigration.total_subscriptions > 0 && productsMigration.total_products > 0;
			const isProductsInProgress = statusData.status === 'products_migrating';
			const isSubscriptionsInProgress = statusData.status === 'subscriptions_migrating';
			const isPaused = statusData.status === 'paused';
			const isMigrationActive = isProductsInProgress || isSubscriptionsInProgress || isPaused;

			return `
				<div class="wcs-wizard-step-content">
					<h2>Go Live</h2>
					<p class="wcs-wizard-description">Final steps to complete your migration and go live with Sublium.</p>

					${isMigrationComplete ? `
						<div class="wcs-migrator-success-message">
							<p><strong>✓ Migration Completed Successfully!</strong></p>
							<ul>
								<li>Products migrated: ${productsMigration.created_plans || 0} plans created</li>
								<li>Subscriptions migrated: ${subscriptionsMigration.created_subscriptions || 0} subscriptions</li>
							</ul>
						</div>
					` : `
						<div class="wcs-wizard-info-box">
							<p><strong>Migration not yet complete.</strong></p>
							<p>Please complete products and subscriptions migration before going live.</p>
						</div>
					`}

					<div class="wcs-wizard-golive-steps">
						<div class="wcs-wizard-golive-step">
							<h3>1. Deactivate WooCommerce Subscriptions</h3>
							<p>Deactivate the WooCommerce Subscriptions plugin to prevent conflicts with Sublium.</p>
							<button class="wcs-migrator-button wcs-migrator-button-secondary wcs-wizard-deactivate-wcs" ${!isMigrationComplete || isMigrationActive ? 'disabled' : ''}>
								Deactivate WooCommerce Subscriptions
							</button>
						</div>

						<div class="wcs-wizard-golive-step">
							<h3>2. Deactivate All Products for Subscriptions</h3>
							<p>Deactivate the All Products for Subscriptions plugin to prevent conflicts with Sublium.</p>
							<button class="wcs-migrator-button wcs-migrator-button-secondary wcs-wizard-deactivate-wcsatt" ${!isMigrationComplete || isMigrationActive ? 'disabled' : ''}>
								Deactivate All Products for Subscriptions
							</button>
						</div>

						<div class="wcs-wizard-golive-step">
							<h3>3. Convert Subscription Products</h3>
							<p>Convert WCS subscription products to regular WooCommerce products (only products with no active subscriptions).</p>
							<button class="wcs-migrator-button wcs-migrator-button-secondary wcs-migrator-load-products" ${!isMigrationComplete || isMigrationActive ? 'disabled' : ''}>
								Load Products
							</button>
							<div class="wcs-migrator-products-list" style="display: none; margin-top: 15px;">
								<table class="wp-list-table widefat fixed striped">
									<thead>
										<tr>
											<th>Product Name</th>
											<th>Type</th>
											<th>Active Subscriptions</th>
											<th>Actions</th>
										</tr>
									</thead>
									<tbody class="wcs-migrator-products-tbody">
									</tbody>
								</table>
							</div>
						</div>
					</div>
				</div>
			`;
		},

		renderWizardActions: function(discoveryData, statusData) {
			const subscriptionsMigration = statusData.subscriptions_migration || {};
			const productsMigration = statusData.products_migration || {};
			const isProductsCompleted = productsMigration.processed_products >= productsMigration.total_products && productsMigration.total_products > 0;
			const isSubscriptionsCompleted = subscriptionsMigration.processed_subscriptions >= subscriptionsMigration.total_subscriptions && subscriptionsMigration.total_subscriptions > 0;
			const isProductsInProgress = statusData.status === 'products_migrating';
			const isSubscriptionsInProgress = statusData.status === 'subscriptions_migrating';
			const isPaused = statusData.status === 'paused';
			const isMigrationActive = isProductsInProgress || isSubscriptionsInProgress || isPaused;

			let buttons = '';

			// Step-specific action buttons
			// Allow subscriptions migration to start independently (subscriptions use plan_data directly)
			// Disable if any migration is active
			if (this.currentStep === 2 && !isSubscriptionsCompleted && !isSubscriptionsInProgress && !isPaused) {
				buttons += `<button type="button" class="wcs-migrator-button wcs-migrator-button-primary wcs-migrator-start-subscriptions" ${isMigrationActive ? 'disabled' : ''}>
					Start Subscriptions Migration
				</button>`;
			}

			if (this.currentStep === 3 && !isProductsCompleted && !isProductsInProgress && !isPaused) {
				buttons += `<button class="wcs-migrator-button wcs-migrator-button-primary wcs-migrator-start-products" ${isMigrationActive ? 'disabled' : ''}>
					Start Products Migration
				</button>`;
			}

			// Progress control buttons
			if (isProductsInProgress || isSubscriptionsInProgress) {
				if (isPaused) {
					buttons += `<button class="wcs-migrator-button wcs-migrator-button-primary wcs-migrator-resume">
						Resume Migration
					</button>`;
				} else {
					buttons += `<button class="wcs-migrator-button wcs-migrator-button-secondary wcs-migrator-pause">
						Pause Migration
					</button>`;
				}
				buttons += `<button class="wcs-migrator-button wcs-migrator-button-secondary wcs-migrator-cancel">
					Cancel Migration
				</button>`;
			}

			// Navigation buttons - allow Previous navigation (going back doesn't interfere with migrations)
			if (this.currentStep > 1) {
				buttons += `<button type="button" class="wcs-migrator-button wcs-migrator-button-secondary wcs-wizard-prev">
					Previous
				</button>`;
			}

			if (this.currentStep < this.totalSteps) {
				// Disable next button during active migrations
				buttons += `<button class="wcs-migrator-button wcs-migrator-button-primary wcs-wizard-next" ${isMigrationActive ? 'disabled' : ''}>
					Next
				</button>`;
			}

			// Reset button - disable during active migrations (user should cancel first)
			buttons += `<button class="wcs-migrator-button wcs-migrator-button-secondary wcs-migrator-reset" style="margin-left: 10px;" ${isMigrationActive ? 'disabled' : ''}>
				Reset Migration
			</button>`;

			return buttons;
		},

		handleNextStep: function(e) {
			e.preventDefault();
			e.stopPropagation();
			const $target = $(e.currentTarget);
			// Skip if button is disabled
			if ($target.prop('disabled') || $target.hasClass('disabled')) {
				return;
			}
			if (this.currentStep < this.totalSteps) {
				// Set navigation flag to prevent auto-step changes
				this.isNavigating = true;
				this.currentStep++;
				// Force re-render immediately to show the new step
				if (this.discoveryData) {
					// If we have cached data, render immediately
					const self = this;
					$.ajax({
						url: this.apiUrl + 'status',
						method: 'GET',
						beforeSend: function(xhr) {
							xhr.setRequestHeader('X-WP-Nonce', self.nonce);
						},
						success: function(statusData) {
							self.renderWizard(self.discoveryData, statusData);
							self.isNavigating = false;
						}
					});
				} else {
					// Otherwise load fresh data
					this.loadFeasibility();
				}
			}
		},

		handlePrevStep: function(e) {
			e.preventDefault();
			e.stopPropagation();
			const $target = $(e.currentTarget);
			// Skip if button is disabled
			if ($target.prop('disabled') || $target.hasClass('disabled')) {
				return;
			}
			if (this.currentStep > 1) {
				const newStep = this.currentStep - 1;
				this.currentStep = newStep;
				// Force re-render immediately to show the new step
				if (this.discoveryData) {
					// If we have cached data, render immediately
					$.ajax({
						url: this.apiUrl + 'status',
						method: 'GET',
						beforeSend: (xhr) => {
							xhr.setRequestHeader('X-WP-Nonce', this.nonce);
						},
						success: (statusData) => {
							this.renderWizard(this.discoveryData, statusData);
						}
					});
				} else {
					// Otherwise load fresh data
					this.loadFeasibility();
				}
			}
		},

		handleStepClick: function(e) {
			e.preventDefault();
			const step = parseInt($(e.currentTarget).data('step'));
			if (step && step >= 1 && step <= this.totalSteps) {
				this.currentStep = step;
				this.loadFeasibility();
			}
		},

		handleDeactivateWCS: function(e) {
			e.preventDefault();
			const $target = $(e.currentTarget);
			// Skip if button is disabled
			if ($target.prop('disabled')) {
				return;
			}
			if (!confirm('Are you sure you want to deactivate WooCommerce Subscriptions? This action cannot be undone easily.')) {
				return;
			}

			const self = this;
			$.ajax({
				url: this.apiUrl + 'plugins/deactivate-wcs',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', WCSMigrator.nonce);
				},
				success: function(response) {
					if (response && response.success) {
						alert(response.message || 'WooCommerce Subscriptions plugin deactivated successfully');
						$target.prop('disabled', true).text('Deactivated');
					} else {
						WCSMigrator.showError(response.message || 'Failed to deactivate WooCommerce Subscriptions plugin');
					}
				},
				error: function(xhr) {
					let errorMsg = 'Failed to deactivate WooCommerce Subscriptions plugin';
					if (xhr.responseJSON && xhr.responseJSON.message) {
						errorMsg = xhr.responseJSON.message;
					}
					WCSMigrator.showError(errorMsg);
				}
			});
		},

		handleDeactivateWCSATT: function(e) {
			e.preventDefault();
			const $target = $(e.currentTarget);
			// Skip if button is disabled
			if ($target.prop('disabled')) {
				return;
			}
			if (!confirm('Are you sure you want to deactivate All Products for Subscriptions? This action cannot be undone easily.')) {
				return;
			}

			const self = this;
			$.ajax({
				url: this.apiUrl + 'plugins/deactivate-wcsatt',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', WCSMigrator.nonce);
				},
				success: function(response) {
					if (response && response.success) {
						alert(response.message || 'All Products for Subscriptions plugin deactivated successfully');
						$target.prop('disabled', true).text('Deactivated');
					} else {
						WCSMigrator.showError(response.message || 'Failed to deactivate All Products for Subscriptions plugin');
					}
				},
				error: function(xhr) {
					let errorMsg = 'Failed to deactivate All Products for Subscriptions plugin';
					if (xhr.responseJSON && xhr.responseJSON.message) {
						errorMsg = xhr.responseJSON.message;
					}
					WCSMigrator.showError(errorMsg);
				}
			});
		},

		handleConvertAllProducts: function(e) {
			e.preventDefault();
			if (!confirm('Are you sure you want to convert all subscription products? This will convert all products that have no active subscriptions.')) {
				return;
			}
			// Implementation needed
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
						WCSMigrator.currentStep = 5;
						WCSMigrator.loadFeasibility();
					} else {
						WCSMigrator.loadFeasibility();
					}
				},
				error: function() {
					WCSMigrator.showError('Failed to load migration status');
				}
			});
		},

		renderProgress: function(data) {
			const html = `
				<div class="wcs-wizard">
					<div class="wcs-wizard-steps">
						${this.renderStepIndicator()}
					</div>
					<div class="wcs-wizard-content">
						<div class="wcs-wizard-step-content">
							<h2>Migration in Progress</h2>
							${this.renderProgressContent(data)}
						</div>
					</div>
					<div class="wcs-wizard-actions">
						${this.renderWizardActions(this.discoveryData, data)}
					</div>
				</div>
			`;
			$('#wcs-sublium-migrator-app').html(html);
		},

		renderProgressContent: function(data) {
			const productsMigration = data.products_migration || {};
			const subscriptionsMigration = data.subscriptions_migration || {};
			const isProductsActive = data.status === 'products_migrating';
			const isSubscriptionsActive = data.status === 'subscriptions_migrating';

			// Calculate progress percentages from actual counts
			const productsTotal = parseInt(productsMigration.total_products || 0, 10);
			const productsProcessed = parseInt(productsMigration.processed_products || 0, 10);
			const productsProgress = productsTotal > 0 ? Math.min(100, (productsProcessed / productsTotal) * 100) : 0;

			const subscriptionsTotal = parseInt(subscriptionsMigration.total_subscriptions || 0, 10);
			const subscriptionsProcessed = parseInt(subscriptionsMigration.processed_subscriptions || 0, 10);
			const subscriptionsProgress = subscriptionsTotal > 0 ? Math.min(100, (subscriptionsProcessed / subscriptionsTotal) * 100) : 0;

			return `
				${(productsTotal > 0 || isProductsActive) ? `
					<div class="wcs-migrator-progress">
						<div class="wcs-migrator-progress-label">
							Products Migration: ${productsProcessed} / ${productsTotal}
							${productsMigration.created_plans ? ` (${productsMigration.created_plans} plans created)` : ''}
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
							${subscriptionsMigration.created_subscriptions ? ` (${subscriptionsMigration.created_subscriptions} created)` : ''}
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
			`;
		},

		startStatusPolling: function() {
			if (this.statusInterval) {
				clearInterval(this.statusInterval);
			}
			this.statusInterval = setInterval(function() {
				WCSMigrator.checkMigrationStatus();
			}, 5000);
		},

		stopStatusPolling: function() {
			if (this.statusInterval) {
				clearInterval(this.statusInterval);
				this.statusInterval = null;
			}
		},

		handleStartProductsMigration: function(e) {
			e.preventDefault();
			e.stopPropagation();
			const $target = $(e.currentTarget);
			// Skip if button is disabled
			if ($target.prop('disabled') || $target.hasClass('disabled')) {
				return;
			}
			if (!confirm('Are you sure you want to start products migration? This will process all subscription products and create plans in the background.')) {
				return;
			}

			const self = this;
			// Disable button immediately to prevent double-clicks
			$target.prop('disabled', true);

			$.ajax({
				url: this.apiUrl + 'start-products',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', WCSMigrator.nonce);
				},
				success: function(response) {
					if (response && response.success) {
						// Refresh the wizard to show progress
						WCSMigrator.loadFeasibility();
					} else {
						const errorMsg = (response && response.message) ? response.message : 'Failed to start products migration';
						WCSMigrator.showError(errorMsg);
						// Re-enable button on error
						$target.prop('disabled', false);
					}
				},
				error: function(xhr, status, error) {
					let errorMsg = 'Failed to start products migration';
					if (xhr.responseJSON && xhr.responseJSON.message) {
						errorMsg = xhr.responseJSON.message;
					} else if (xhr.responseText) {
						try {
							const parsed = JSON.parse(xhr.responseText);
							if (parsed.message) {
								errorMsg = parsed.message;
							}
						} catch(e) {
							// Ignore parse errors
						}
					}
					WCSMigrator.showError(errorMsg + ' (Status: ' + xhr.status + ')');
					// Re-enable button on error
					$target.prop('disabled', false);
				}
			});
		},

		handleStartSubscriptionsMigration: function(e) {
			e.preventDefault();
			e.stopPropagation();
			const $target = $(e.currentTarget);
			// Skip if button is disabled
			if ($target.prop('disabled') || $target.hasClass('disabled')) {
				return;
			}

			if (this.discoveryData && this.discoveryData.readiness) {
				const readinessStatus = this.discoveryData.readiness.status;
				if (readinessStatus === 'partial') {
					const incompatibleGateways = this.discoveryData.gateways.filter(g => !g.compatible);
					let warningMessage = '⚠️ WARNING: Some payment gateways are not compatible with Sublium.\n\n';
					warningMessage += 'Incompatible Gateways:\n';
					incompatibleGateways.forEach(gateway => {
						warningMessage += `• ${gateway.gateway_title} (${gateway.gateway_id}) - ${gateway.subscription_count} subscription(s)\n`;
					});
					warningMessage += '\nSubscriptions using these gateways will be migrated but may require manual payment method updates.\n\n';
					warningMessage += 'Do you want to proceed with the migration?';

					if (!confirm(warningMessage)) {
						return;
					}
				} else if (readinessStatus === 'blocked') {
					alert('Migration is blocked. Please resolve the issues before proceeding.');
					return;
				}
			}

			if (!confirm('Are you sure you want to start subscriptions migration? This will process all subscriptions in the background.')) {
				return;
			}

			// Disable button immediately to prevent double-clicks
			$target.prop('disabled', true);
			const self = this;

			$.ajax({
				url: this.apiUrl + 'start-subscriptions',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', WCSMigrator.nonce);
				},
				success: function(response) {
					if (response && response.success) {
						// Refresh the wizard to show progress
						WCSMigrator.loadFeasibility();
					} else {
						const errorMsg = (response && response.message) ? response.message : 'Failed to start subscriptions migration';
						WCSMigrator.showError(errorMsg);
						// Re-enable button on error
						$target.prop('disabled', false);
					}
				},
				error: function(xhr) {
					let errorMsg = 'Failed to start subscriptions migration';
					if (xhr.responseJSON && xhr.responseJSON.message) {
						errorMsg = xhr.responseJSON.message;
					}
					WCSMigrator.showError(errorMsg);
					// Re-enable button on error
					$target.prop('disabled', false);
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
				success: function(response) {
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
				success: function(response) {
					WCSMigrator.checkMigrationStatus();
				},
				error: function() {
					WCSMigrator.showError('Failed to resume migration');
				}
			});
		},

		handleCancelMigration: function(e) {
			e.preventDefault();
			if (!confirm('Are you sure you want to cancel the migration? This will stop the current migration process.')) {
				return;
			}
			$.ajax({
				url: this.apiUrl + 'cancel',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', WCSMigrator.nonce);
				},
				success: function(response) {
					WCSMigrator.currentStep = 1;
					WCSMigrator.loadFeasibility();
				},
				error: function() {
					WCSMigrator.showError('Failed to cancel migration');
				}
			});
		},

		handleResetMigration: function(e) {
			e.preventDefault();
			const $target = $(e.currentTarget);
			// Skip if button is disabled
			if ($target.prop('disabled')) {
				return;
			}
			if (!confirm('Are you sure you want to reset the migration? This will clear all migration progress and allow you to start fresh.')) {
				return;
			}
			$.ajax({
				url: this.apiUrl + 'reset',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', WCSMigrator.nonce);
				},
				success: function(response) {
					if (response && response.success !== false) {
						WCSMigrator.currentStep = 1;
						WCSMigrator.loadFeasibility();
					} else {
						const errorMsg = (response && response.message) ? response.message : 'Failed to reset migration';
						WCSMigrator.showError(errorMsg);
					}
				},
				error: function(xhr, status, error) {
					let errorMsg = 'Failed to reset migration';
					if (xhr.responseJSON && xhr.responseJSON.message) {
						errorMsg = xhr.responseJSON.message;
					} else if (xhr.responseText) {
						try {
							const parsed = JSON.parse(xhr.responseText);
							if (parsed.message) {
								errorMsg = parsed.message;
							}
						} catch(e) {
							// Ignore parse errors
						}
					}
					WCSMigrator.showError(errorMsg + ' (Status: ' + xhr.status + ')');
				}
			});
		},

		handleLoadProducts: function(e) {
			e.preventDefault();
			const $target = $(e.currentTarget);
			// Skip if button is disabled
			if ($target.prop('disabled')) {
				return;
			}
			$.ajax({
				url: this.apiUrl + 'products/subscription-products',
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', WCSMigrator.nonce);
				},
				success: function(response) {
					if (response.success && response.data && response.data.products) {
						WCSMigrator.renderProductsList(response.data.products);
					} else {
						WCSMigrator.showError('No products found or failed to load products');
					}
				},
				error: function() {
					WCSMigrator.showError('Failed to load products');
				}
			});
		},

		renderProductsList: function(products) {
			const tbody = $('.wcs-migrator-products-tbody');
			tbody.empty();

			if (products.length === 0) {
				tbody.append('<tr><td colspan="4">No subscription products found.</td></tr>');
				return;
			}

			products.forEach(function(product) {
				const row = `
					<tr>
						<td>${product.name}</td>
						<td>${product.type}</td>
						<td>${product.active_subscriptions}</td>
						<td>
							${product.can_convert ? `
								<button class="wcs-migrator-button wcs-migrator-button-secondary wcs-migrator-convert-product" data-product-id="${product.id}">
									Convert
								</button>
							` : '<span class="wcs-migrator-status incompatible">Cannot Convert</span>'}
						</td>
					</tr>
				`;
				tbody.append(row);
			});

			$('.wcs-migrator-products-list').show();
		},

		handleConvertProduct: function(e) {
			e.preventDefault();
			const productId = $(e.currentTarget).data('product-id');
			if (!productId || !confirm('Are you sure you want to convert this product to a simple product?')) {
				return;
			}

			$.ajax({
				url: this.apiUrl + 'products/convert',
				method: 'POST',
				data: { product_id: productId },
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', WCSMigrator.nonce);
				},
				success: function(response) {
					if (response.success) {
						alert('Product converted successfully!');
						WCSMigrator.handleLoadProducts(e);
					} else {
						WCSMigrator.showError(response.message || 'Failed to convert product');
					}
				},
				error: function() {
					WCSMigrator.showError('Failed to convert product');
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
