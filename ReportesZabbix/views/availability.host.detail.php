<?php

/** @var array $data */

function aarh_tag(string $name, $content = null, array $attributes = [], bool $paired = true): CTag {
	$tag = new CTag($name, $paired, $content);
	foreach ($attributes as $attribute => $value) {
		$tag->setAttribute($attribute, $value);
	}
	return $tag;
}

function aarh_duration(int $seconds): string {
	$seconds = max(0, $seconds);
	$days = intdiv($seconds, 86400);
	$hours = intdiv($seconds % 86400, 3600);
	$minutes = intdiv($seconds % 3600, 60);
	$parts = [];
	if ($days > 0) $parts[] = $days.' d';
	if ($hours > 0 || $days > 0) $parts[] = $hours.' h';
	$parts[] = $minutes.' min';
	return implode(' ', $parts);
}

function aarh_number(?float $value, string $units = ''): string {
	if ($value === null) return 'Sin datos';
	if ($units === 's' && $value < 10) return number_format($value * 1000, 2, ',', '.').' ms';
	return number_format($value, 2, ',', '.').($units !== '' ? ' '.$units : '');
}

function aarh_rate(?float $value, string $units = ''): string {
	if ($value === null) return '—';
	$unit = trim($units) !== '' ? trim($units) : 'bps';
	$labels = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
	$index = 0;
	while (abs($value) >= 1000 && $index < count($labels) - 1) {
		$value /= 1000;
		$index++;
	}
	if (stripos($unit, 'bps') === false && stripos($unit, 'Bps') === false) {
		return aarh_number($value, $unit);
	}
	return number_format($value, 2, ',', '.').' '.$labels[$index];
}

function aarh_interface_value(?array $item, bool $rate = false): string {
	if ($item === null) return '—';
	return $rate ? aarh_rate($item['current'] ?? null, (string) ($item['units'] ?? ''))
		: aarh_number($item['current'] ?? null, (string) ($item['units'] ?? ''));
}

function aarh_bytes(?float $bytes): string {
	if ($bytes === null) return '—';
	$units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
	$index = 0;
	while ($bytes >= 1024 && $index < count($units) - 1) {
		$bytes /= 1024;
		$index++;
	}
	return number_format($bytes, $index > 2 ? 1 : 0, ',', '.').' '.$units[$index];
}

function aarh_usage_bar(?float $value, string $status = 'unknown'): CTag {
	if ($value === null) {
		return aarh_tag('span', 'Sin datos', ['class' => 'aarh-muted']);
	}

	$percent = max(0.0, min(100.0, $value));
	$allowed_statuses = ['ok', 'warning', 'critical'];
	if (!in_array($status, $allowed_statuses, true)) {
		$status = $percent >= 90 ? 'critical' : ($percent >= 80 ? 'warning' : 'ok');
	}

	$formatted = number_format($value, 1, ',', '.').' %';
	return aarh_tag('div', [
		aarh_tag('span', '', [
			'class' => 'aarh-usage-fill aarh-usage-fill-'.$status,
			'style' => 'width: '.number_format($percent, 2, '.', '').'%'
		]),
		aarh_tag('strong', $formatted)
	], [
		'class' => 'aarh-usage-bar',
		'role' => 'meter',
		'aria-label' => 'Utilización del disco: '.$formatted,
		'aria-valuemin' => '0',
		'aria-valuemax' => '100',
		'aria-valuenow' => number_format($percent, 1, '.', '')
	]);
}

function aarh_linechart(array $item, float $scale = 100.0): CTag {
	$points = $item['points'] ?? [];
	if (!$points) {
		return aarh_tag('div', [aarh_tag('strong', 'Sin histórico agregado'),
			aarh_tag('span', 'Revise el período o la retención de trends.')], ['class' => 'aarh-no-trend']);
	}
	$values = array_map(static fn(array $point): float => (float) $point['value'], $points);
	$maximum = $scale > 0 ? $scale : max(1.0, max($values));
	return aarh_tag('div', null, [
		'class' => 'aarh-line-chart', 'data-points' => json_encode($points, JSON_UNESCAPED_SLASHES),
		'data-max' => (string) $maximum,
		'data-warning' => is_finite((float) ($item['warning_threshold'] ?? INF)) ? (string) $item['warning_threshold'] : '',
		'data-critical' => is_finite((float) ($item['critical_threshold'] ?? INF)) ? (string) $item['critical_threshold'] : '',
		'data-units' => (string) ($item['units'] ?? '')
	]);
}

function aarh_status(string $status, string $prefix = ''): CTag {
	$labels = ['ok' => 'Normal', 'warning' => 'Advertencia', 'critical' => 'Crítico',
		'unknown' => 'Sin datos', 'unrated' => 'Sin umbral'];
	return aarh_tag('span', $prefix.($labels[$status] ?? $status), ['class' => 'aarh-health aarh-health-'.$status]);
}

function aarh_resource_card(string $title, ?array $item, string $expected, float $scale = 100.0): CTag {
	if ($item === null) {
		return aarh_tag('article', [aarh_tag('h3', $title), aarh_tag('div', [
			aarh_tag('strong', 'Sin ítem compatible'), aarh_tag('span', 'Esperado: '.$expected)
		], ['class' => 'aarh-empty-resource'])], ['class' => 'aarh-resource aarh-resource-empty']);
	}
	$units = (string) ($item['units'] ?? '');
	$badges = [aarh_status((string) ($item['current_status'] ?? 'unknown'), 'Ahora: '),
		aarh_status((string) ($item['period_status'] ?? 'unknown'), 'Pico: ')];
	$exposure = ($item['period_status'] ?? '') === 'unrated'
		? 'Métrica informativa sin umbral genérico.'
		: 'Sobre umbral (aprox.): '.$item['warning_hours'].' h advertencia · '.$item['critical_hours'].' h crítico';
	return aarh_tag('article', [
		aarh_tag('div', [aarh_tag('h3', $title), aarh_tag('div', $badges, ['class' => 'aarh-resource-badges'])], ['class' => 'aarh-resource-title']),
		aarh_tag('div', [aarh_tag('span', 'Actual', ['class' => 'aarh-metric-label']), aarh_tag('strong', aarh_number($item['current'], $units)),
			aarh_tag('span', 'Promedio', ['class' => 'aarh-metric-label']), aarh_tag('strong', aarh_number($item['average'], $units)),
			aarh_tag('span', 'Máximo', ['class' => 'aarh-metric-label']), aarh_tag('strong', aarh_number($item['maximum'], $units))], ['class' => 'aarh-metrics']),
		aarh_linechart($item, $scale),
		aarh_tag('small', $exposure.($item['peak_clock'] ? ' · Pico: '.date('Y-m-d H:i', $item['peak_clock']) : ''), ['class' => 'aarh-exposure']),
		aarh_tag('small', $item['name'].' · '.$item['key_'], ['class' => 'aarh-item-name'])
	], ['class' => 'aarh-resource aarh-resource-'.($item['period_status'] ?? 'unknown')]);
}

$host = $data['host'];
$filters = $data['filters'];
$resources = $data['resources'];
$summary = $data['problem_summary'];
$availability = $data['availability'];
$profile = $data['device_profile'] ?? ['type' => 'Host genérico', 'hint' => ''];
$back_query = ['action' => 'availability.reports', 'report_type' => $filters['report_type'],
	'period' => $filters['period'], 'date_from' => $filters['date_from'], 'date_to' => $filters['date_to'],
	'groupid' => $filters['groupid'], 'hostid' => 0, 'availability_mode' => $filters['availability_mode'],
	'sla_target' => $filters['sla_target'], 'company_name' => $filters['company_name'],
	'report_title' => $filters['report_title']];
$back_query['maintenance_mode'] = $filters['maintenance_mode'];

$page = new CHtmlPage();
$page->setTitle($data['title']);
if ($host === null) {
	$page->addItem(aarh_tag('div', [aarh_tag('h1', 'Host no disponible'),
		aarh_tag('p', 'El host no existe o el usuario no tiene permisos para consultarlo.'),
		aarh_tag('a', 'Volver al reporte', ['href' => 'zabbix.php?'.http_build_query($back_query), 'class' => 'aar-button'])], ['class' => 'aar-host-detail']));
	$page->show();
	return;
}

$address = '—';
foreach ($host['interfaces'] ?? [] as $interface) {
	if ((int) ($interface['main'] ?? 0) === 1) {
		$address = (int) ($interface['useip'] ?? 1) === 1 ? $interface['ip'] : $interface['dns'];
		break;
	}
}
$inventory = is_array($host['inventory'] ?? null) ? $host['inventory'] : [];
$availability_value = $availability['availability'] ?? null;
$availability_text = $availability_value === null ? 'Sin indicador' : number_format((float) $availability_value, 3, ',', '.').' %';
$availability_sources = $availability['availability_sources'] ?? ['agent' => false, 'icmp' => false];
$source_names = [];
if ($availability_sources['agent']) $source_names[] = 'agent.ping';
if ($availability_sources['icmp']) $source_names[] = 'icmpping';
$source_text = $source_names ? implode(' + ', $source_names) : 'Ninguno detectado';
$policy_labels = ['agent' => 'Solo Agent', 'icmp' => 'Solo ICMP', 'any' => 'Cualquiera que falle',
	'both' => 'Ambos deben fallar'];
$policy_text = $policy_labels[$availability['availability_policy'] ?? 'any'] ?? 'Automático';
$severity_names = ['No clasificado', 'Información', 'Advertencia', 'Media', 'Alta', 'Desastre'];
$severity_classes = ['aarh-sev-0', 'aarh-sev-1', 'aarh-sev-2', 'aarh-sev-3', 'aarh-sev-4', 'aarh-sev-5'];

$cards = [
	['Disponibilidad', $availability_text, 'Por agent.ping/icmpping'],
	['Indicador detectado', $source_text, $source_names ? 'Política aplicada: '.$policy_text : 'Revise los triggers de disponibilidad'],
	['SLA objetivo', number_format((float) ($availability['sla_target'] ?? $filters['sla_target']), 3, ',', '.').' %', 'Global o etiqueta report_sla del host'],
	['Mantenimiento', (int) ($host['maintenance_status'] ?? 0) === 1 ? 'Activo' : 'No',
		$filters['maintenance_mode'] === 'exclude' ? 'Eventos suprimidos excluidos' : 'Eventos suprimidos incluidos'],
	['Caída por ping', aarh_duration((int) ($availability['downtime'] ?? 0)), 'Tiempo único de indisponibilidad'],
	['Problemas del período', (string) $summary['total'], 'Eventos individuales'],
	['Problemas activos', (string) $summary['active'], 'Sin recuperación al cierre'],
	['Tiempo afectado único', aarh_duration((int) $summary['unique_duration']), 'Sin duplicar alertas simultáneas'],
	['Horas-evento acumuladas', aarh_duration((int) $summary['event_duration']), 'Suma de todas las alertas'],
	['Salud operativa', $summary['health'], 'Separada del cálculo de SLA']
];
$card_nodes = [];
foreach ($cards as [$label, $value, $help]) {
	$card_nodes[] = aarh_tag('div', [aarh_tag('span', $label), aarh_tag('strong', $value), aarh_tag('small', $help)], ['class' => 'aarh-card']);
}

$resource_nodes = [
	aarh_resource_card('CPU', $resources['cpu'], 'system.cpu.util', 100),
	aarh_resource_card('Memoria RAM', $resources['memory'], 'vm.memory.util o vm.memory.size[pused]', 100),
	aarh_resource_card('Latencia ICMP', $resources['latency'], 'icmppingsec', 0),
	aarh_resource_card('Pérdida ICMP', $resources['loss'], 'icmppingloss', 100)
];
foreach ($resources['extra_metrics'] ?? [] as $extra) {
	$resource_nodes[] = aarh_resource_card((string) $extra['name'], $extra, (string) $extra['key_'], 0);
}

$sensor_rows = [];
foreach ($resources['sensors'] ?? [] as $sensor) {
	$sensor_rows[] = aarh_tag('tr', [
		aarh_tag('td', aarh_tag('strong', (string) $sensor['name'])),
		aarh_tag('td', (string) $sensor['sensor_type']),
		aarh_tag('td', aarh_number($sensor['current'], (string) $sensor['units'])),
		aarh_tag('td', aarh_number($sensor['average'], (string) $sensor['units'])),
		aarh_tag('td', aarh_number($sensor['maximum'], (string) $sensor['units'])),
		aarh_tag('td', aarh_status((string) ($sensor['display_status'] ?? 'unrated')))
	]);
}

$interface_summary = $resources['interface_summary'] ?? ['total' => 0, 'up' => 0, 'down' => 0,
	'errors' => 0, 'changes' => 0, 'traffic_in' => 0, 'traffic_out' => 0];
$interface_cards = [];
foreach ([
	['Interfaces físicas', $interface_summary['total']], ['UP', $interface_summary['up']],
	['DOWN', $interface_summary['down']], ['Con errores/descartes', $interface_summary['errors']],
	['Con cambios', $interface_summary['changes']], ['Tráfico entrada', aarh_rate($interface_summary['traffic_in'])],
	['Tráfico salida', aarh_rate($interface_summary['traffic_out'])]
] as [$label, $value]) {
	$interface_cards[] = aarh_tag('div', [aarh_tag('span', $label), aarh_tag('strong', (string) $value)],
		['class' => 'aarh-network-card']);
}
$interface_rows = [];
foreach ($resources['interfaces'] ?? [] as $interface) {
	$errors = (float) ($interface['in_errors']['maximum'] ?? 0)
		+ (float) ($interface['out_errors']['maximum'] ?? 0);
	$discards = (float) ($interface['in_discards']['maximum'] ?? 0)
		+ (float) ($interface['out_discards']['maximum'] ?? 0);
	$period_labels = ['stable' => 'Estable', 'changes' => 'Cambios de estado', 'errors' => 'Con errores', 'down' => 'Sin enlace'];
	$period_classes = ['stable' => 'ok', 'changes' => 'warning', 'errors' => 'warning', 'down' => 'critical'];
	$interface_rows[] = aarh_tag('tr', [
		aarh_tag('td', aarh_tag('strong', $interface['name'])),
		aarh_tag('td', aarh_tag('span', $interface['up'] ? 'UP' : 'DOWN',
			['class' => 'aarh-if-state '.($interface['up'] ? 'aarh-if-up' : 'aarh-if-down'),
				'title' => (string) ($interface['status_source'] ?? '')])),
		aarh_tag('td', aarh_tag('span', $interface['usage'], ['class' => 'aarh-if-usage'])),
		aarh_tag('td', aarh_interface_value($interface['traffic_in'], true)),
		aarh_tag('td', aarh_interface_value($interface['traffic_out'], true)),
		aarh_tag('td', number_format($errors, 2, ',', '.')),
		aarh_tag('td', number_format($discards, 2, ',', '.')),
		aarh_tag('td', aarh_interface_value($interface['speed'], true)),
		aarh_tag('td', aarh_status($period_classes[$interface['period_status']] ?? 'unknown',
			($period_labels[$interface['period_status']] ?? 'Sin datos').': '))
	], ['class' => 'aarh-interface-row', 'data-status' => $interface['up'] ? 'up' : 'down',
		'data-errors' => ($errors > 0 || $discards > 0) ? 'yes' : 'no',
		'data-search' => strtolower($interface['name'])]);
}

$fortinet_rows = [];
foreach (($resources['fortinet']['metrics'] ?? []) as $metric) {
	$value = is_numeric($metric['value'])
		? aarh_number((float) $metric['value'], (string) $metric['units'])
		: (string) $metric['value'];
	$fortinet_rows[] = aarh_tag('tr', [aarh_tag('td', aarh_tag('strong', $metric['label'])),
		aarh_tag('td', $value), aarh_tag('td', $metric['key_'])]);
}

$disk_rows = [];
foreach ($resources['disks'] as $disk) {
	if (($disk['projection_status'] ?? 'insufficient') === 'insufficient') {
		$projection = 'Requiere al menos 7 días de tendencias';
	}
	elseif (($disk['projection_status'] ?? '') === 'stable') {
		$projection = 'Sin crecimiento confiable';
	}
	else {
		$confidence_labels = ['low' => 'baja', 'medium' => 'media', 'high' => 'alta'];
		$projection_parts = ['Crecimiento: '.number_format($disk['growth_per_day'], 2, ',', '.').' %/día'];
		if (($disk['bytes_growth_per_day'] ?? null) !== null) {
			$projection_parts[] = aarh_bytes($disk['bytes_growth_per_day']).'/día';
		}
		foreach ([80, 90, 95] as $target) {
			if (isset($disk['targets'][$target])) {
				$projection_parts[] = $target.' % en '.(int) round($disk['targets'][$target]['days']).' días';
			}
		}
		$projection_parts[] = 'Confianza '.($confidence_labels[$disk['confidence']] ?? 'baja');
		$projection = implode(' · ', $projection_parts);
	}
	if ($disk['bytes_to_target'] !== null && $disk['bytes_to_target'] > 0) {
		$projection .= ' · Liberar '.aarh_bytes($disk['bytes_to_target']).' para llegar al 80 %';
	}
	$disk_rows[] = aarh_tag('tr', [aarh_tag('td', aarh_tag('strong', $disk['filesystem'])),
		aarh_tag('td', aarh_bytes($disk['total_bytes'])), aarh_tag('td', aarh_bytes($disk['used_bytes'])),
		aarh_tag('td', aarh_bytes($disk['free_bytes'])), aarh_tag('td',
			aarh_usage_bar($disk['current'], (string) ($disk['current_status'] ?? 'unknown'))),
		aarh_tag('td', aarh_number($disk['maximum'], '%')), aarh_tag('td', [
			aarh_status((string) ($disk['current_status'] ?? 'unknown'), 'Ahora: '),
			aarh_status((string) ($disk['period_status'] ?? 'unknown'), 'Pico: ')]),
		aarh_tag('td', $projection), aarh_tag('td', aarh_linechart($disk, 100))]);
}
if (!$disk_rows) {
	$disk_rows[] = aarh_tag('tr', aarh_tag('td', 'No se encontraron vfs.fs.size[...,pused/pfree]. Vincule una plantilla con descubrimiento de filesystems.',
		['colspan' => '9', 'class' => 'aar-empty']));
}

$category_options = [aarh_tag('option', 'Todas las categorías', ['value' => ''])];
foreach (array_keys($summary['categories']) as $category) {
	$category_options[] = aarh_tag('option', $category, ['value' => $category]);
}
$problem_group_rows = [];
foreach ($data['problem_groups'] ?? [] as $group) {
	$severity = max(0, min(5, (int) $group['severity']));
	$problem_name = [aarh_tag('strong', $group['name'])];
	if ($group['flapping']) {
		$labels = ['moderate' => 'Flapping moderado', 'high' => 'Flapping alto', 'severe' => 'Flapping severo'];
		$problem_name[] = aarh_tag('span', $labels[$group['flapping_level']] ?? 'Flapping',
			['class' => 'aarh-flapping aarh-flapping-'.$group['flapping_level']]);
	}
	$frequency_text = ($filters['time_to'] - $filters['time_from']) >= 86400
		? number_format($group['frequency_per_day'], 2, ',', '.').' eventos/día'
		: number_format($group['frequency_per_hour'], 2, ',', '.').' eventos/h';
	$recurrence_text = $group['recurrence_interval'] !== null
		? ' · Intervalo medio '.aarh_duration($group['recurrence_interval'])
		: '';
	$problem_name[] = aarh_tag('small', $frequency_text.$recurrence_text.' · Duración media '.
		aarh_duration($group['average_duration']).' · '.$group['recommendation'], ['class' => 'aarh-problem-analysis']);
	$problem_group_rows[] = aarh_tag('tr', [
		aarh_tag('td', $group['active'] ? aarh_tag('span', 'Activo', ['class' => 'aarh-active']) : 'Recuperado'),
		aarh_tag('td', aarh_tag('span', $severity_names[$severity], ['class' => 'aarh-severity '.$severity_classes[$severity]])),
		aarh_tag('td', $group['category']),
		aarh_tag('td', $problem_name),
		aarh_tag('td', (string) $group['count'], ['class' => 'aar-number']),
		aarh_tag('td', aarh_duration((int) $group['total_duration'])),
		aarh_tag('td', date('Y-m-d H:i', (int) $group['first'])), aarh_tag('td', date('Y-m-d H:i', (int) $group['last'])),
		aarh_tag('td', $group['acknowledged'] ? 'Sí' : 'No')
	], ['class' => 'aarh-problem-row', 'data-severity' => (string) $severity,
		'data-category' => $group['category'], 'data-status' => $group['active'] ? 'active' : 'recovered',
		'data-search' => strtolower($group['name'].' '.$group['category'])]);
}
if (!$problem_group_rows) {
	$problem_group_rows[] = aarh_tag('tr', aarh_tag('td', 'No se encontraron problemas en el período seleccionado.',
		['colspan' => '9', 'class' => 'aar-empty']));
}

$event_rows = [];
foreach ($data['problems'] as $problem) {
	$severity = max(0, min(5, (int) $problem['severity']));
	$event_rows[] = aarh_tag('tr', [aarh_tag('td', date('Y-m-d H:i:s', $problem['start'])),
		aarh_tag('td', $problem['active'] ? 'Activo' : date('Y-m-d H:i:s', $problem['end'])),
		aarh_tag('td', aarh_duration($problem['duration'])),
		aarh_tag('td', aarh_tag('span', $severity_names[$severity], ['class' => 'aarh-severity '.$severity_classes[$severity]])),
		aarh_tag('td', $problem['category']), aarh_tag('td', $problem['name']),
		aarh_tag('td', $problem['acknowledged'] ? 'Sí' : 'No')]);
}

$period_text = date('Y-m-d H:i:s', $filters['time_from']).' — '.date('Y-m-d H:i:s', $filters['time_to']);
$groups = implode(', ', array_column($host['hostgroups'] ?? [], 'name'));
$description = array_filter([$inventory['os'] ?? '', $inventory['type'] ?? '', $inventory['model'] ?? '']);
$recommendations = $data['recommendations'] ?? [];
if (($availability['downtime'] ?? 0) > 0 && $availability_sources['agent'] && !$availability_sources['icmp']) {
	array_unshift($recommendations,
		'La disponibilidad se basa únicamente en agent.ping. Agregue ICMP para diferenciar una caída total de una falla del agente.');
}
$recommendations = array_slice(array_values(array_unique($recommendations)), 0, 8);

$fortinet_section = null;
if (!empty($resources['fortinet']['detected'])) {
	$transport = implode(' + ', $resources['fortinet']['transport'] ?? []);
	$fortinet_section = aarh_tag('section', [
		aarh_tag('div', [aarh_tag('h2', 'Resumen FortiGate'),
			aarh_tag('span', 'Origen detectado: '.($transport !== '' ? $transport : 'ítems Zabbix'), ['class' => 'aarh-muted'])],
			['class' => 'aarh-section-heading']),
		$fortinet_rows
			? aarh_tag('div', aarh_tag('table', [aarh_tag('thead', aarh_tag('tr', [aarh_tag('th', 'Métrica'),
				aarh_tag('th', 'Valor actual'), aarh_tag('th', 'Clave Zabbix')])), aarh_tag('tbody', $fortinet_rows)],
				['class' => 'aar-table aarh-table aarh-fortinet-table']), ['class' => 'aar-table-wrap'])
			: aarh_tag('p', 'Se detectó FortiGate, pero no hay ítems de identidad, sesiones, HA, VPN o licencias disponibles.',
				['class' => 'aarh-muted'])
	], ['class' => 'aarh-network-section']);
}

$sensor_section = $sensor_rows ? aarh_tag('section', [
	aarh_tag('div', [aarh_tag('h2', 'Sensores ambientales y hardware'),
		aarh_tag('span', 'Vista compacta sin gráficas individuales.', ['class' => 'aarh-muted'])],
		['class' => 'aarh-section-heading']),
	aarh_tag('div', aarh_tag('table', [aarh_tag('thead', aarh_tag('tr', [aarh_tag('th', 'Sensor'),
		aarh_tag('th', 'Tipo'), aarh_tag('th', 'Actual / Last'), aarh_tag('th', 'Promedio'),
		aarh_tag('th', 'Máximo'), aarh_tag('th', 'Estado')])), aarh_tag('tbody', $sensor_rows)],
		['class' => 'aar-table aarh-table aarh-sensor-table']), ['class' => 'aar-table-wrap'])
], ['class' => 'aarh-network-section']) : null;

$interface_section = $interface_rows ? aarh_tag('section', [
	aarh_tag('div', [aarh_tag('h2', 'Estado de interfaces físicas'),
		aarh_tag('span', 'Tráfico actual y máximos de errores/descartes del período.', ['class' => 'aarh-muted'])],
		['class' => 'aarh-section-heading']),
	aarh_tag('div', $interface_cards, ['class' => 'aarh-network-cards']),
	aarh_tag('div', [
		aarh_tag('input', null, ['type' => 'search', 'id' => 'aarh-interface-search',
			'placeholder' => 'Buscar interfaz…'], false),
		aarh_tag('select', [aarh_tag('option', 'Todos los estados', ['value' => '']),
			aarh_tag('option', 'UP', ['value' => 'up']), aarh_tag('option', 'DOWN', ['value' => 'down'])],
			['id' => 'aarh-interface-status']),
		aarh_tag('select', [aarh_tag('option', 'Con y sin errores', ['value' => '']),
			aarh_tag('option', 'Solo con errores', ['value' => 'yes']),
			aarh_tag('option', 'Sin errores', ['value' => 'no'])], ['id' => 'aarh-interface-errors']),
		aarh_tag('span', '', ['id' => 'aarh-interface-count', 'class' => 'aarh-muted'])
	], ['class' => 'aarh-interface-filters']),
	aarh_tag('div', aarh_tag('table', [aarh_tag('thead', aarh_tag('tr', [aarh_tag('th', 'Interfaz / descripción'),
		aarh_tag('th', 'Estado'), aarh_tag('th', 'Uso'), aarh_tag('th', 'Tráfico entrada'),
		aarh_tag('th', 'Tráfico salida'), aarh_tag('th', 'Errores máx.'), aarh_tag('th', 'Descartes máx.'),
		aarh_tag('th', 'Velocidad'), aarh_tag('th', 'Estado del período')])),
		aarh_tag('tbody', $interface_rows, ['id' => 'aarh-interface-body'])],
		['class' => 'aar-table aarh-table aarh-interface-table']), ['class' => 'aar-table-wrap'])
], ['class' => 'aarh-network-section']) : null;

$disk_section = ($resources['disks'] || !in_array($profile['type'], ['Dispositivo de red', 'FortiGate', 'Firewall'], true))
	? [aarh_tag('h2', 'Utilización de discos'),
		aarh_tag('div', aarh_tag('table', [aarh_tag('thead', aarh_tag('tr', [aarh_tag('th', 'Disco'), aarh_tag('th', 'Total'),
			aarh_tag('th', 'Usado'), aarh_tag('th', 'Libre'), aarh_tag('th', 'Utilización'), aarh_tag('th', 'Máximo'),
			aarh_tag('th', 'Salud'), aarh_tag('th', 'Proyección'), aarh_tag('th', 'Tendencia')])), aarh_tag('tbody', $disk_rows)],
			['class' => 'aar-table aarh-table aarh-disk-table']), ['class' => 'aar-table-wrap'])]
	: [];

$content = [
	aarh_tag('div', [aarh_tag('strong', $filters['company_name'], ['class' => 'aar-print-company', 'data-aar-company' => '']),
		aarh_tag('span', $filters['report_title'].' · Detalle técnico del host', [
			'class' => 'aar-print-title', 'data-aar-detail-title' => ''
		])], ['class' => 'aar-print-brand']),
	aarh_tag('div', [aarh_tag('div', [aarh_tag('h1', 'Detalle técnico: '.$host['name']), aarh_tag('p', $period_text, ['class' => 'aarh-muted'])]),
		aarh_tag('div', [aarh_tag('button', 'Imprimir / PDF', ['type' => 'button', 'id' => 'aar-print', 'class' => 'aar-button']),
			aarh_tag('a', '← Volver al reporte', ['href' => 'zabbix.php?'.http_build_query($back_query), 'class' => 'aar-button'])], ['class' => 'aar-toolbar'])], ['class' => 'aarh-title-row']),
	aarh_tag('div', [aarh_tag('strong', $host['host']), ' · '.$address.' · '.$groups.($description ? ' · '.implode(' · ', $description) : ''),
		aarh_tag('span', $profile['type'].' — '.$profile['hint'], ['class' => 'aarh-profile'])], ['class' => 'aarh-host-meta']),
	aarh_tag('div', $card_nodes, ['class' => 'aarh-cards']),
	$recommendations ? aarh_tag('section', [aarh_tag('h2', 'Conclusiones y recomendaciones'),
		aarh_tag('ul', array_map(static fn(string $recommendation): CTag =>
			aarh_tag('li', $recommendation), $recommendations))], ['class' => 'aarh-insights']) : null,
	aarh_tag('h2', 'Comportamiento de recursos'),
	aarh_tag('div', $resource_nodes, ['class' => 'aarh-resources']),
	$fortinet_section,
	$sensor_section,
	$interface_section,
	...$disk_section,
	aarh_tag('div', [aarh_tag('h2', 'Problemas agrupados'), aarh_tag('span', 'Las repeticiones se consolidan; “Flapping” indica 3 o más apariciones.', ['class' => 'aarh-muted'])], ['class' => 'aarh-section-heading']),
	aarh_tag('div', [aarh_tag('input', null, ['type' => 'search', 'id' => 'aarh-problem-search', 'placeholder' => 'Buscar problema…'], false),
		aarh_tag('select', [aarh_tag('option', 'Todas las severidades', ['value' => '']), aarh_tag('option', 'Información', ['value' => '1']),
			aarh_tag('option', 'Advertencia', ['value' => '2']), aarh_tag('option', 'Media', ['value' => '3']),
			aarh_tag('option', 'Alta', ['value' => '4']), aarh_tag('option', 'Desastre', ['value' => '5'])], ['id' => 'aarh-problem-severity']),
		aarh_tag('select', $category_options, ['id' => 'aarh-problem-category']),
		aarh_tag('select', [aarh_tag('option', 'Todos los estados', ['value' => '']), aarh_tag('option', 'Activos', ['value' => 'active']),
			aarh_tag('option', 'Recuperados', ['value' => 'recovered'])], ['id' => 'aarh-problem-status']),
		aarh_tag('span', '', ['id' => 'aarh-problem-count', 'class' => 'aarh-muted'])], ['class' => 'aarh-problem-filters']),
	aarh_tag('div', aarh_tag('table', [aarh_tag('thead', aarh_tag('tr', [aarh_tag('th', 'Estado'), aarh_tag('th', 'Severidad'),
		aarh_tag('th', 'Categoría'), aarh_tag('th', 'Problema'), aarh_tag('th', 'Veces'), aarh_tag('th', 'Horas-evento'),
		aarh_tag('th', 'Primero'), aarh_tag('th', 'Último'), aarh_tag('th', 'Reconocido')])),
		aarh_tag('tbody', $problem_group_rows, ['id' => 'aarh-problem-body'])],
		['class' => 'aar-table aarh-table aarh-problem-table']), ['class' => 'aar-table-wrap'])
];
if ($event_rows) {
	$content[] = aarh_tag('details', [aarh_tag('summary', 'Ver eventos individuales ('.count($event_rows).')'),
		aarh_tag('div', aarh_tag('table', [aarh_tag('thead', aarh_tag('tr', [aarh_tag('th', 'Inicio'), aarh_tag('th', 'Recuperación'),
			aarh_tag('th', 'Duración'), aarh_tag('th', 'Severidad'), aarh_tag('th', 'Categoría'), aarh_tag('th', 'Problema'),
			aarh_tag('th', 'Reconocido')])), aarh_tag('tbody', $event_rows)], ['class' => 'aar-table aarh-table']), ['class' => 'aar-table-wrap'])], ['class' => 'aarh-events']);
}
$content[] = aarh_tag('div', 'Versión del módulo: 1.0 · Datos consultados bajo demanda mediante la API de Zabbix.', ['class' => 'aar-footer']);
$page->addItem(aarh_tag('section', array_values(array_filter($content,
	static fn($item): bool => $item !== null)), ['class' => 'aar-report aar-host-detail']));
$page->show();
