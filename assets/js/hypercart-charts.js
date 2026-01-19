/* global HypercartChartsSettings */

(function () {
	'use strict';

	const REGISTRY = new WeakMap();

	function parsePayloadFromElement(el) {
		const raw = el.getAttribute('data-hypercart-chart');
		if (!raw) return null;
		try {
			return JSON.parse(raw);
		} catch (e) {
			console.warn('[HypercartCharts] Failed to parse data-hypercart-chart JSON', e);
			return null;
		}
	}

	function normalizeLocaleTag(loc) {
		if (!loc || typeof loc !== 'string') return undefined;
		// WordPress locales are often like en_US; Intl expects BCP-47 (en-US).
		const candidate = loc.replace('_', '-');
		try {
			// Validate.
			Intl.getCanonicalLocales(candidate);
			return candidate;
		} catch (e) {
			return undefined;
		}
	}

	function buildConfigFromPayload(payload) {
		// Payload is `{ chart, config }` from PHP. We pass `config` directly to Chart.js.
		const cfg = payload && payload.config ? payload.config : null;
		if (!cfg || !cfg.options) return cfg;

		const settings = (typeof HypercartChartsSettings !== 'undefined') ? HypercartChartsSettings : {};
		const tz = settings.tz || 'UTC';
		const loc = normalizeLocaleTag(settings.loc) || undefined;

		// Default time formatting for epoch-ms `x` values.
		let dtf;
		try {
			dtf = new Intl.DateTimeFormat(loc, {
				timeZone: tz,
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				hour: '2-digit',
				minute: '2-digit',
			});
		} catch (e) {
			// Fallback: no locale/timezone customization.
			dtf = new Intl.DateTimeFormat(undefined, {
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				hour: '2-digit',
				minute: '2-digit',
			});
		}

		cfg.options.scales = cfg.options.scales || {};
		cfg.options.scales.x = cfg.options.scales.x || {};
		cfg.options.scales.x.ticks = cfg.options.scales.x.ticks || {};

		if (!cfg.options.scales.x.ticks.callback) {
			cfg.options.scales.x.ticks.callback = function (value) {
				// `value` is the tick value for linear scales.
				const d = new Date(Number(value));
				if (Number.isNaN(d.getTime())) return String(value);
				return dtf.format(d);
			};
		}

		cfg.options.plugins = cfg.options.plugins || {};
		cfg.options.plugins.tooltip = cfg.options.plugins.tooltip || {};
		cfg.options.plugins.tooltip.callbacks = cfg.options.plugins.tooltip.callbacks || {};

		if (!cfg.options.plugins.tooltip.callbacks.title) {
			cfg.options.plugins.tooltip.callbacks.title = function (items) {
				if (!items || !items.length) return '';
				const x = items[0].parsed && items[0].parsed.x;
				const d = new Date(Number(x));
				return Number.isNaN(d.getTime()) ? '' : dtf.format(d);
			};
		}

		return cfg;
	}

	function render(canvasEl, payload) {
		if (!canvasEl) return null;
		if (!window.Chart) {
			console.warn('[HypercartCharts] Chart.js not found on window.Chart (did chart.umd.min.js load?)');
			return null;
		}

		// Prevent duplicate render on the same canvas.
		if (REGISTRY.has(canvasEl)) {
			return REGISTRY.get(canvasEl);
		}

		const cfg = buildConfigFromPayload(payload);
		if (!cfg) {
			console.warn('[HypercartCharts] Missing config in payload');
			return null;
		}

		const chart = new window.Chart(canvasEl, cfg);
		REGISTRY.set(canvasEl, chart);
		return chart;
	}

	function destroy(canvasEl) {
		const chart = REGISTRY.get(canvasEl);
		if (chart && typeof chart.destroy === 'function') {
			chart.destroy();
		}
		REGISTRY.delete(canvasEl);
	}

	function autoRender() {
		const els = document.querySelectorAll('canvas[data-hypercart-chart]');
		els.forEach((el) => {
			if (REGISTRY.has(el)) return;
			const payload = parsePayloadFromElement(el);
			if (!payload) return;
			render(el, payload);
		});
	}

	window.HypercartCharts = {
		render,
		destroy,
		autoRender,
		settings: (typeof HypercartChartsSettings !== 'undefined') ? HypercartChartsSettings : {},
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', autoRender);
	} else {
		autoRender();
	}
})();
