<?php

/** @var array $data */

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="disponibilidad_'.date('Ymd_His').'.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'wb');
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, [
	'Host', 'Nombre visible', 'IP/DNS', 'Grupos', 'Indicadores', 'Disponibilidad (%)',
	'Fuentes disponibilidad', 'Politica aplicada', 'SLA objetivo (%)', 'Cumple SLA', 'Tiempo disponible (s)',
	'Tiempo caido (s)', 'Incidentes', 'MTTR (s)', 'Mayor caida (s)', 'Salud operativa',
	'Problemas activos', 'Mantenimiento', 'Estado'
], ';');

foreach ($data['rows'] as $row) {
	$sources = [];
	if ($row['availability_sources']['agent']) $sources[] = 'agent.ping';
	if ($row['availability_sources']['icmp']) $sources[] = 'icmpping';
	fputcsv($output, [
		$row['host'],
		$row['name'],
		$row['interface']['address'],
		implode(', ', $row['groups']),
		$row['indicator_count'],
		$row['availability'] === null ? '' : number_format($row['availability'], 4, ',', ''),
		implode(' + ', $sources),
		$row['availability_policy'],
		number_format($row['sla_target'], 4, ',', ''),
		$row['availability'] === null ? 'No evaluado'
			: ($row['availability'] >= $row['sla_target'] ? 'Si' : 'No'),
		$row['uptime'],
		$row['downtime'],
		$row['incident_count'],
		$row['mttr'],
		$row['max_outage'],
		$row['health']['status'] ?? 'Normal',
		$row['health']['active'] ?? 0,
		$row['in_maintenance'] ? 'Si' : 'No',
		$row['in_maintenance'] && $data['filters']['maintenance_mode'] === 'exclude' ? 'En mantenimiento'
			: ($row['indicator_count'] === 0 ? 'Sin indicador' : ($row['current_problem'] ? 'Caida activa' : 'Disponible'))
	], ';');
}

fclose($output);
