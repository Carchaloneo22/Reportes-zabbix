<?php

/** @var array $data */

function aar_tag(string $name, $content = null, array $attributes = [], bool $paired = true): CTag {
	$tag = new CTag($name, $paired, $content);
	foreach ($attributes as $attribute => $value) {
		$tag->setAttribute($attribute, $value);
	}
	return $tag;
}

function aar_option(string $value, string $label, string $selected): CTag {
	$option = aar_tag('option', $label, ['value' => $value]);
	if ($value === $selected) {
		$option->setAttribute('selected', 'selected');
	}
	return $option;
}

function aar_duration(int $seconds): string {
	$seconds = max(0, $seconds);
	$days = intdiv($seconds, 86400);
	$hours = intdiv($seconds % 86400, 3600);
	$minutes = intdiv($seconds % 3600, 60);
	$parts = [];
	if ($days > 0) {
		$parts[] = $days.' d';
	}
	if ($hours > 0 || $days > 0) {
		$parts[] = $hours.' h';
	}
	$parts[] = $minutes.' min';
	return implode(' ', $parts);
}

function aar_availability(?float $value, float $target = 99.9): array {
	if ($value === null) {
		return ['Sin indicador', 'aar-na'];
	}
	$class = $value >= $target ? 'aar-ok' : ($value >= max(0.0, $target - 0.9) ? 'aar-warn' : 'aar-bad');
	return [number_format($value, 3, ',', '.').' %', $class];
}

function aar_hidden(string $name, string $value): CTag {
	return aar_tag('input', null, ['type' => 'hidden', 'name' => $name, 'value' => $value], false);
}

$filters = $data['filters'];
$summary = $data['summary'];
$form_items = [aar_hidden('action', 'availability.reports')];

$group_options = [aar_option('0', 'Todos los grupos', (string) $filters['groupid'])];
foreach ($data['groups'] ?? [] as $group) {
	$group_options[] = aar_option((string) $group['groupid'], $group['name'], (string) $filters['groupid']);
}

$host_options = [aar_option('0', 'Todos los hosts', (string) $filters['hostid'])];
foreach ($data['hosts_filter'] ?? [] as $host) {
	$host_options[] = aar_option((string) $host['hostid'], $host['name'], (string) $filters['hostid']);
}

$fields = [
	['Tipo de reporte', aar_tag('select', [
		aar_option('technical', 'Técnico', $filters['report_type']),
		aar_option('managerial', 'Gerencial', $filters['report_type'])
	], ['name' => 'report_type'])],
	['Periodo', aar_tag('select', [
		aar_option('daily', 'Diario (hoy)', $filters['period']),
		aar_option('weekly', 'Semanal (lunes a hoy)', $filters['period']),
		aar_option('custom', 'Personalizado', $filters['period'])
	], ['name' => 'period', 'id' => 'aar-period'])],
	['Desde', aar_tag('input', null, [
		'type' => 'date', 'name' => 'date_from', 'value' => $filters['date_from'], 'id' => 'aar-date-from'
	], false)],
	['Hasta', aar_tag('input', null, [
		'type' => 'date', 'name' => 'date_to', 'value' => $filters['date_to'], 'id' => 'aar-date-to'
	], false)],
	['Grupo', aar_tag('select', $group_options, ['name' => 'groupid'])],
	['Host', aar_tag('select', $host_options, ['name' => 'hostid'])],
	['Disponibilidad', aar_tag('select', [
		aar_option('auto', 'Automático (Agent preferido)', $filters['availability_mode']),
		aar_option('agent', 'Solo agent.ping', $filters['availability_mode']),
		aar_option('icmp', 'Solo icmpping', $filters['availability_mode']),
		aar_option('both', 'Caída solo si fallan ambos', $filters['availability_mode']),
		aar_option('any', 'Caída si falla cualquiera', $filters['availability_mode'])
	], ['name' => 'availability_mode'])],
	['Mantenimientos', aar_tag('select', [
		aar_option('exclude', 'Excluir suprimidos', $filters['maintenance_mode']),
		aar_option('include', 'Incluir todos', $filters['maintenance_mode'])
	], ['name' => 'maintenance_mode'])],
	['SLA objetivo', aar_tag('input', null, [
		'type' => 'number', 'name' => 'sla_target', 'value' => (string) $filters['sla_target'],
		'min' => '0', 'max' => '100', 'step' => '0.001'
	], false)]
];

foreach ($fields as [$label, $control]) {
	$form_items[] = aar_tag('label', [$label, $control], ['class' => 'aar-field']);
}
$form_items[] = aar_tag('details', [
	aar_tag('summary', 'Personalizar encabezado'),
	aar_tag('div', [
		aar_tag('label', ['Empresa o institución', aar_tag('input', null, [
			'type' => 'text', 'name' => 'company_name', 'id' => 'aar-company-name',
			'value' => $filters['company_name'], 'maxlength' => '100',
			'placeholder' => 'Opcional: nombre de la empresa'
		], false)], ['class' => 'aar-field']),
		aar_tag('label', ['Título del reporte', aar_tag('input', null, [
			'type' => 'text', 'name' => 'report_title', 'id' => 'aar-report-title',
			'value' => $filters['report_title'], 'maxlength' => '140', 'required' => 'required'
		], false)], ['class' => 'aar-field'])
	], ['class' => 'aar-brand-fields'])
], ['class' => 'aar-brand-settings']);
$form_items[] = aar_tag('button', 'Generar reporte', [
	'type' => 'button', 'class' => 'aar-primary', 'id' => 'aar-generate'
]);

$query = [
	'action' => 'availability.reports.csv',
	'report_type' => $filters['report_type'],
	'period' => $filters['period'],
	'date_from' => $filters['date_from'],
	'date_to' => $filters['date_to'],
	'groupid' => $filters['groupid'],
	'hostid' => $filters['hostid'],
	'availability_mode' => $filters['availability_mode'],
	'maintenance_mode' => $filters['maintenance_mode'],
	'sla_target' => $filters['sla_target'],
	'company_name' => $filters['company_name'],
	'report_title' => $filters['report_title']
];

$toolbar = aar_tag('div', [
	aar_tag('a', 'Exportar CSV', ['href' => 'zabbix.php?'.http_build_query($query), 'class' => 'aar-button']),
	aar_tag('button', 'Imprimir / Guardar PDF', ['type' => 'button', 'class' => 'aar-button', 'id' => 'aar-print'])
], ['class' => 'aar-toolbar']);

$cards = [
	['Hosts', $summary['total'], 'aar-blue'],
	['Evaluados', $summary['evaluated'], 'aar-green'],
	['Disponibilidad media', $summary['average_availability'] === null
		? '—' : number_format($summary['average_availability'], 3, ',', '.').' %', 'aar-purple'],
	['Cumplen SLA', $summary['compliant'], 'aar-green'],
	['No cumplen', $summary['noncompliant'], 'aar-red'],
	['Sin indicador', $summary['without_indicator'], 'aar-gray'],
	['Incidentes', $summary['total_incidents'], 'aar-orange'],
	['Horas-host de caída', aar_duration($summary['total_downtime']), 'aar-red'],
	['Salud normal', $summary['healthy'], 'aar-green'],
	['Degradados', $summary['degraded'], 'aar-orange']
];

$card_nodes = [];
foreach ($cards as [$label, $value, $class]) {
	$card_nodes[] = aar_tag('div', [
		aar_tag('span', $label, ['class' => 'aar-card-label']),
		aar_tag('strong', (string) $value, ['class' => 'aar-card-value'])
	], ['class' => 'aar-card '.$class]);
}

$executive = null;
if ($filters['report_type'] === 'managerial') {
	$comparison = $data['comparison'] ?? null;
	$delta = $comparison['delta_availability'] ?? null;
	$trend_text = $delta === null ? 'Sin período anterior comparable'
		: (($delta >= 0 ? '▲ ' : '▼ ').number_format(abs($delta), 3, ',', '.').' puntos vs. período anterior');
	$worst = array_values(array_filter($data['rows'], static fn(array $row): bool =>
		$row['availability'] !== null && $row['availability'] < ($row['sla_target'] ?? 100)));
	usort($worst, static fn(array $a, array $b): int => $a['availability'] <=> $b['availability']);
	$worst_nodes = [];
	foreach (array_slice($worst, 0, 5) as $row) {
		$worst_nodes[] = aar_tag('li', [aar_tag('strong', $row['name']),
			' · '.number_format($row['availability'], 3, ',', '.').'% · '.aar_duration($row['downtime'])]);
	}
	$health_rows = array_values(array_filter($data['rows'], static fn(array $row): bool =>
		($row['health']['level'] ?? 'ok') !== 'ok'));
	usort($health_rows, static function(array $a, array $b): int {
		return [$b['health']['max_severity'], $b['health']['active']]
			<=> [$a['health']['max_severity'], $a['health']['active']];
	});
	$health_nodes = [];
	foreach (array_slice($health_rows, 0, 5) as $row) {
		$health_nodes[] = aar_tag('li', [aar_tag('strong', $row['name']),
			' · '.$row['health']['status'].' · '.$row['health']['active'].' activos']);
	}
	$trend_nodes = [aar_tag('span', 'Tendencia'), aar_tag('strong', $trend_text)];
	if ($comparison !== null) {
		$trend_nodes[] = aar_tag('small', 'Incidentes: '.($comparison['delta_incidents'] >= 0 ? '+' : '').$comparison['delta_incidents']);
	}
	$executive = aar_tag('section', [
		aar_tag('div', [aar_tag('span', 'Cumplimiento global'),
			aar_tag('strong', $summary['compliance_rate'] === null ? '—'
				: number_format($summary['compliance_rate'], 1, ',', '.').' %')]),
		aar_tag('div', $trend_nodes),
		aar_tag('div', [aar_tag('span', 'Hosts degradados'), aar_tag('strong', (string) $summary['degraded']),
			aar_tag('small', 'Problemas activos de cualquier categoría')]),
		aar_tag('div', [aar_tag('span', 'Peor SLA'), $worst_nodes ? aar_tag('ol', $worst_nodes) : aar_tag('strong', 'Todos cumplen')]),
		aar_tag('div', [aar_tag('span', 'Peor salud'), $health_nodes ? aar_tag('ol', $health_nodes) : aar_tag('strong', 'Todos normales')])
	], ['class' => 'aar-executive']);
}

$header = $filters['report_type'] === 'managerial'
	? ['Host', 'Grupo', 'Disponibilidad', 'SLA objetivo', 'Cumplimiento', 'Incidentes', 'Tiempo de caída', 'Salud actual', 'Estado actual', 'Detalle']
	: ['Host', 'IP/DNS', 'Grupo', 'Indicador detectado', 'Disponibilidad', 'Caída', 'Incidentes', 'MTTR', 'Mayor caída', 'Salud actual', 'Estado actual', 'Detalle'];

$head_cells = [];
foreach ($header as $column) {
	$head_cells[] = aar_tag('th', $column);
}
$body_rows = [];

foreach ($data['rows'] as $row) {
	[$availability_text, $availability_class] = aar_availability($row['availability'], $row['sla_target']);
	$status = $row['in_maintenance'] && $filters['maintenance_mode'] === 'exclude'
		? ['En mantenimiento', 'aar-status-maintenance']
		: ($row['indicator_count'] === 0
		? ['Sin indicador', 'aar-status-na']
		: ($row['current_problem'] ? ['Con caída activa', 'aar-status-bad'] : ['Disponible', 'aar-status-ok']));
	$compliance = $row['availability'] === null
		? ['No evaluado', 'aar-na']
		: ($row['availability'] >= $row['sla_target'] ? ['Cumple', 'aar-ok'] : ['No cumple', 'aar-bad']);

	$cells = [aar_tag('td', aar_tag('strong', $row['name']))];
	if ($filters['report_type'] === 'technical') {
		$cells[] = aar_tag('td', $row['interface']['address'] !== '' ? $row['interface']['address'] : '—');
	}
	$cells[] = aar_tag('td', implode(', ', $row['groups']));
	if ($filters['report_type'] === 'technical') {
		$sources = [];
		if ($row['availability_sources']['agent']) $sources[] = aar_tag('span', 'Agent', ['class' => 'aar-source aar-source-agent']);
		if ($row['availability_sources']['icmp']) $sources[] = aar_tag('span', 'ICMP', ['class' => 'aar-source aar-source-icmp']);
		$policy_labels = ['agent' => 'Usa Agent', 'icmp' => 'Usa ICMP', 'any' => 'Falla cualquiera',
			'both' => 'Deben fallar ambos'];
		$source_nodes = $sources ?: [aar_tag('span', 'Ninguno', ['class' => 'aar-source aar-source-none'])];
		$source_nodes[] = aar_tag('small', $policy_labels[$row['availability_policy']] ?? 'Automático',
			['class' => 'aar-policy']);
		$cells[] = aar_tag('td', $source_nodes);
	}
	$cells[] = aar_tag('td', aar_tag('span', $availability_text, ['class' => 'aar-pill '.$availability_class]));
	if ($filters['report_type'] === 'managerial') {
		$cells[] = aar_tag('td', number_format($row['sla_target'], 3, ',', '.').' %');
		$cells[] = aar_tag('td', aar_tag('span', $compliance[0], ['class' => 'aar-pill '.$compliance[1]]));
	}
	if ($filters['report_type'] === 'managerial') {
		$cells[] = aar_tag('td', (string) $row['incident_count'], ['class' => 'aar-number']);
		$cells[] = aar_tag('td', aar_duration($row['downtime']));
	}
	else {
		$cells[] = aar_tag('td', aar_duration($row['downtime']));
		$cells[] = aar_tag('td', (string) $row['incident_count'], ['class' => 'aar-number']);
	}
	if ($filters['report_type'] === 'technical') {
		$cells[] = aar_tag('td', aar_duration($row['mttr']));
		$cells[] = aar_tag('td', aar_duration($row['max_outage']));
	}
	$health = $row['health'] ?? ['status' => 'Normal', 'level' => 'ok', 'active' => 0, 'names' => []];
	$cells[] = aar_tag('td', aar_tag('span', $health['status'].' ('.$health['active'].')', [
		'class' => 'aar-health aar-health-'.$health['level'],
		'title' => implode(' · ', $health['names'] ?? [])
	]));
	$cells[] = aar_tag('td', aar_tag('span', $status[0], ['class' => 'aar-status '.$status[1]]));
	$detail_query = [
		'action' => 'availability.host.detail',
		'hostid' => $row['hostid'],
		'report_type' => $filters['report_type'],
		'period' => $filters['period'],
		'date_from' => $filters['date_from'],
		'date_to' => $filters['date_to'],
		'groupid' => $filters['groupid'],
		'availability_mode' => $filters['availability_mode'],
		'maintenance_mode' => $filters['maintenance_mode'],
		'sla_target' => $filters['sla_target'],
		'company_name' => $filters['company_name'],
		'report_title' => $filters['report_title']
	];
	$cells[] = aar_tag('td', aar_tag('a', 'Ver detalle', [
		'href' => 'zabbix.php?'.http_build_query($detail_query), 'class' => 'aar-detail-link'
	]));

	$body_rows[] = aar_tag('tr', $cells);

	if ($filters['report_type'] === 'technical' && $row['incidents']) {
		$incident_rows = [];
		foreach ($row['incidents'] as $incident) {
			$incident_rows[] = aar_tag('li', [
				aar_tag('strong', date('Y-m-d H:i:s', $incident['start']).' — '),
				$incident['name'].' · '.aar_duration($incident['duration']).
					($incident['active'] ? ' · ACTIVO' : '').
					($incident['acknowledged'] ? ' · reconocido' : ' · no reconocido')
			]);
		}
		$body_rows[] = aar_tag('tr', aar_tag('td', aar_tag('details', [
			aar_tag('summary', 'Detalle de incidentes de '.$row['name']),
			aar_tag('ul', $incident_rows, ['class' => 'aar-incidents'])
		]), ['colspan' => (string) count($header)]), ['class' => 'aar-detail-row']);
	}
}

if (!$body_rows) {
	$body_rows[] = aar_tag('tr', aar_tag('td', 'No se encontraron hosts para los filtros seleccionados.', [
		'colspan' => (string) count($header), 'class' => 'aar-empty'
	]));
}

$period_text = date('Y-m-d H:i:s', $filters['time_from']).' — '.date('Y-m-d H:i:s', $filters['time_to']);
$note = aar_tag('div', [
	aar_tag('strong', 'Criterio de disponibilidad: '),
	'El SLA se calcula exclusivamente con triggers asociados a agent.ping e icmpping. ',
	'Los demás problemas de CPU, memoria, almacenamiento, red, servicios y hardware se muestran en el detalle técnico del host sin descontar disponibilidad. ',
	'Los intervalos simultáneos se unen para no duplicar tiempo de caída. ',
	'La política seleccionada determina si se usa Agent, ICMP o la combinación de ambos. ',
	$filters['maintenance_mode'] === 'exclude'
		? 'Los eventos suprimidos o asociados a mantenimiento se excluyen.'
		: 'Los eventos suprimidos o asociados a mantenimiento están incluidos.',
	' El SLA individual puede definirse con la etiqueta de host report_sla o sla_target.'
], ['class' => 'aar-note']);

$page = new CHtmlPage();
$page->setTitle($data['title']);
$page->addItem(aar_tag('section', array_values(array_filter([
	aar_tag('div', [aar_tag('strong', $filters['company_name'], ['class' => 'aar-print-company', 'data-aar-company' => '']),
		aar_tag('span', $filters['report_title'], ['class' => 'aar-print-title', 'data-aar-report-title' => ''])], ['class' => 'aar-print-brand']),
	aar_tag('div', [
		aar_tag('div', [
			aar_tag('h1', ($filters['report_type'] === 'managerial' ? 'Reporte gerencial: ' : 'Reporte técnico: ').$filters['report_title'], [
				'data-aar-heading' => '', 'data-prefix' => $filters['report_type'] === 'managerial' ? 'Reporte gerencial' : 'Reporte técnico'
			]),
			aar_tag('p', 'Periodo: '.$period_text.' · Generado: '.date('Y-m-d H:i:s', $data['generated_at']))
		], ['class' => 'aar-heading']),
		$toolbar
	], ['class' => 'aar-title-row']),
	aar_tag('form', $form_items, ['method' => 'get', 'action' => 'zabbix.php', 'class' => 'aar-filter', 'id' => 'aar-filter']),
	$executive,
	aar_tag('div', $card_nodes, ['class' => 'aar-cards']),
	aar_tag('div', aar_tag('table', [
		aar_tag('thead', aar_tag('tr', $head_cells)),
		aar_tag('tbody', $body_rows)
	], ['class' => 'aar-table aar-report-table aar-report-table-'.$filters['report_type']]),
		['class' => 'aar-table-wrap']),
	$note,
	aar_tag('div', 'Motor: '.$data['engine'].' · Versión del módulo: 1.0', ['class' => 'aar-footer'])
], static fn($item): bool => $item !== null)), ['class' => 'aar-report']));
$page->show();
