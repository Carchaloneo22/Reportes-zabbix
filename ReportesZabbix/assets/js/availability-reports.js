(() => {
	const init = () => {
		const printButton = document.getElementById('aar-print');
		if (printButton) {
			printButton.addEventListener('click', () => window.print());
		}

		const brandingKey = 'advancedAvailabilityReports.branding';
		const companyInput = document.getElementById('aar-company-name');
		const reportTitleInput = document.getElementById('aar-report-title');
		const urlParameters = new URLSearchParams(window.location.search);
		let savedBranding = {};
		try {
			savedBranding = JSON.parse(window.localStorage.getItem(brandingKey) || '{}');
		}
		catch (error) {
			savedBranding = {};
		}

		if (companyInput && !urlParameters.has('company_name') && typeof savedBranding.companyName === 'string') {
			companyInput.value = savedBranding.companyName;
		}
		if (reportTitleInput && !urlParameters.has('report_title') && typeof savedBranding.reportTitle === 'string'
				&& savedBranding.reportTitle.trim()) {
			reportTitleInput.value = savedBranding.reportTitle;
		}

		const applyBranding = () => {
			const companyName = companyInput ? companyInput.value.trim()
				: (urlParameters.get('company_name') || '').trim();
			const reportTitle = (reportTitleInput ? reportTitleInput.value : urlParameters.get('report_title') || '')
				.trim() || 'Monitoreo y disponibilidad de infraestructura';
			document.querySelectorAll('[data-aar-company]').forEach((node) => {
				node.textContent = companyName;
			});
			document.querySelectorAll('[data-aar-report-title]').forEach((node) => {
				node.textContent = reportTitle;
			});
			document.querySelectorAll('[data-aar-detail-title]').forEach((node) => {
				node.textContent = `${reportTitle} · Detalle técnico del host`;
			});
			document.querySelectorAll('[data-aar-heading]').forEach((node) => {
				node.textContent = `${node.dataset.prefix}: ${reportTitle}`;
			});
		};

		const saveBranding = () => {
			if (!companyInput || !reportTitleInput) return;
			try {
				window.localStorage.setItem(brandingKey, JSON.stringify({
					companyName: companyInput.value.trim(),
					reportTitle: reportTitleInput.value.trim() || 'Monitoreo y disponibilidad de infraestructura'
				}));
			}
			catch (error) {
				// La personalización continúa funcionando aunque el navegador bloquee el almacenamiento local.
			}
		};

		[companyInput, reportTitleInput].filter(Boolean).forEach((input) => {
			input.addEventListener('input', () => {
				applyBranding();
				saveBranding();
			});
		});
		applyBranding();

		const svgNode = (name, attributes = {}) => {
			const node = document.createElementNS('http://www.w3.org/2000/svg', name);
			Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, value));
			return node;
		};

		document.querySelectorAll('.aarh-line-chart').forEach((container) => {
			let points;
			try {
				points = JSON.parse(container.dataset.points || '[]');
			}
			catch (error) {
				points = [];
			}
			if (!points.length) return;

			const width = 480;
			const height = 104;
			const padding = {top: 9, right: 8, bottom: 22, left: 8};
			const values = points.map((point) => Number(point.value) || 0);
			const configuredMax = Number(container.dataset.max) || 0;
			const maximum = Math.max(configuredMax, ...values, 1);
			const x = (index) => padding.left + (width - padding.left - padding.right) *
				(index / Math.max(1, points.length - 1));
			const y = (value) => padding.top + (height - padding.top - padding.bottom) *
				(1 - Math.max(0, Math.min(maximum, value)) / maximum);
			const svg = svgNode('svg', {viewBox: `0 0 ${width} ${height}`, role: 'img',
				'aria-label': 'Tendencia del recurso'});

			[['warning', 'aarh-threshold-warning'], ['critical', 'aarh-threshold-critical']].forEach(([key, className]) => {
				const threshold = Number(container.dataset[key]);
				if (!Number.isFinite(threshold) || threshold <= 0 || threshold > maximum) return;
				svg.appendChild(svgNode('line', {x1: padding.left, y1: y(threshold), x2: width - padding.right,
					y2: y(threshold), class: className}));
			});

			const polygonPoints = [`${x(0)},${height - padding.bottom}`]
				.concat(points.map((point, index) => `${x(index)},${y(Number(point.value) || 0)}`))
				.concat([`${x(points.length - 1)},${height - padding.bottom}`]).join(' ');
			svg.appendChild(svgNode('polygon', {points: polygonPoints, class: 'aarh-chart-area'}));
			svg.appendChild(svgNode('polyline', {points: points.map((point, index) =>
				`${x(index)},${y(Number(point.value) || 0)}`).join(' '), class: 'aarh-chart-line'}));

			points.forEach((point, index) => {
				const circle = svgNode('circle', {cx: x(index), cy: y(Number(point.value) || 0), r: 2.8,
					class: 'aarh-chart-point'});
				const title = svgNode('title');
				title.textContent = `${new Date(Number(point.clock) * 1000).toLocaleString()} · ${Number(point.value).toFixed(2)} ${container.dataset.units || ''}`;
				circle.appendChild(title);
				svg.appendChild(circle);
			});

			const firstDate = new Date(Number(points[0].clock) * 1000);
			const lastDate = new Date(Number(points[points.length - 1].clock) * 1000);
			const shortPeriod = (lastDate.getTime() - firstDate.getTime()) <= 172800000;
			const axisLabel = (date) => shortPeriod
				? date.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})
				: date.toLocaleDateString();
			const start = svgNode('text', {x: padding.left, y: height - 5, class: 'aarh-chart-label'});
			start.textContent = axisLabel(firstDate);
			const end = svgNode('text', {x: width - padding.right, y: height - 5, class: 'aarh-chart-label',
				'text-anchor': 'end'});
			end.textContent = axisLabel(lastDate);
			svg.append(start, end);
			container.appendChild(svg);
		});

		const problemRows = Array.from(document.querySelectorAll('.aarh-problem-row'));
		const search = document.getElementById('aarh-problem-search');
		const severity = document.getElementById('aarh-problem-severity');
		const category = document.getElementById('aarh-problem-category');
		const status = document.getElementById('aarh-problem-status');
		const count = document.getElementById('aarh-problem-count');
		if (problemRows.length && search && severity && category && status) {
			const filterProblems = () => {
				const term = search.value.trim().toLocaleLowerCase();
				let visible = 0;
				problemRows.forEach((row) => {
					const show = (!term || (row.dataset.search || '').includes(term)) &&
						(!severity.value || row.dataset.severity === severity.value) &&
						(!category.value || row.dataset.category === category.value) &&
						(!status.value || row.dataset.status === status.value);
					row.hidden = !show;
					if (show) visible++;
				});
				if (count) count.textContent = `${visible} de ${problemRows.length} grupos`;
			};
			[search, severity, category, status].forEach((control) =>
				control.addEventListener(control === search ? 'input' : 'change', filterProblems));
			filterProblems();
		}

		const interfaceRows = Array.from(document.querySelectorAll('.aarh-interface-row'));
		const interfaceSearch = document.getElementById('aarh-interface-search');
		const interfaceStatus = document.getElementById('aarh-interface-status');
		const interfaceErrors = document.getElementById('aarh-interface-errors');
		const interfaceCount = document.getElementById('aarh-interface-count');
		if (interfaceRows.length && interfaceSearch && interfaceStatus && interfaceErrors) {
			const filterInterfaces = () => {
				const term = interfaceSearch.value.trim().toLocaleLowerCase();
				let visible = 0;
				interfaceRows.forEach((row) => {
					const show = (!term || (row.dataset.search || '').includes(term)) &&
						(!interfaceStatus.value || row.dataset.status === interfaceStatus.value) &&
						(!interfaceErrors.value || row.dataset.errors === interfaceErrors.value);
					row.hidden = !show;
					if (show) visible++;
				});
				if (interfaceCount) interfaceCount.textContent = `${visible} de ${interfaceRows.length} interfaces`;
			};
			[interfaceSearch, interfaceStatus, interfaceErrors].forEach((control) =>
				control.addEventListener(control === interfaceSearch ? 'input' : 'change', filterInterfaces));
			filterInterfaces();
		}

		const form = document.getElementById('aar-filter');
		const generateButton = document.getElementById('aar-generate');
		const period = document.getElementById('aar-period');
		const from = document.getElementById('aar-date-from');
		const to = document.getElementById('aar-date-to');
		if (!form || !generateButton || !period || !from || !to) return;

		const updateDateState = () => {
		const custom = period.value === 'custom';
		from.disabled = false;
		to.disabled = false;
		from.readOnly = !custom;
		to.readOnly = !custom;
		from.required = custom;
		to.required = custom;
		};
		const navigate = () => {
		updateDateState();
		if (!form.reportValidity()) {
			return;
		}
		saveBranding();

		const query = new URLSearchParams(new FormData(form));
		window.location.assign(`zabbix.php?${query.toString()}`);
		};

		period.addEventListener('change', updateDateState);
		generateButton.addEventListener('click', (event) => {
		event.preventDefault();
		event.stopPropagation();
		navigate();
		});
		form.addEventListener('submit', (event) => {
		event.preventDefault();
		event.stopPropagation();
		navigate();
		});
		updateDateState();
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init, {once: true});
	}
	else {
		init();
	}
})();
