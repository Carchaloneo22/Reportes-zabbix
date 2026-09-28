<?php

namespace Modules\AdvancedAvailabilityReports\Includes;

use API;

class HostDetailService {

	private const MAX_TREND_POINTS = 48;

	public function build(int $hostid, array $filters): array {
		$hosts = $this->asArray(API::Host()->get([
			'output' => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
			'hostids' => [$hostid],
			'selectHostGroups' => ['groupid', 'name'],
			'selectInterfaces' => ['type', 'main', 'useip', 'ip', 'dns', 'available', 'error'],
			'selectInventory' => ['os', 'type', 'model', 'serialno_a'],
			'preservekeys' => true
		]));

		if (!$hosts || !isset($hosts[(string) $hostid])) {
			return ['host' => null, 'filters' => $filters, 'resources' => [], 'problems' => [],
				'problem_groups' => [], 'problem_summary' => $this->emptyProblemSummary()];
		}

		$host = $hosts[(string) $hostid];
		$problems = $this->getProblems($hostid, $filters);
		$resources = $this->getResources($hostid, $filters, $host);

		$problem_summary = $this->buildProblemSummary($problems);
		$problem_groups = $this->groupProblems($problems, $filters);
		return [
			'host' => $host,
			'filters' => $filters,
			'resources' => $resources,
			'problems' => $problems,
			'problem_groups' => $problem_groups,
			'problem_summary' => $problem_summary,
			'recommendations' => $this->buildRecommendations($resources, $problem_groups),
			'device_profile' => $this->detectDeviceProfile($host, $resources),
			'generated_at' => time()
		];
	}

	private function getProblems(int $hostid, array $filters): array {
		$events = $this->asArray(API::Event()->get([
			'output' => ['eventid', 'objectid', 'clock', 'name', 'severity', 'acknowledged', 'r_eventid', 'suppressed'],
			'hostids' => [$hostid],
			'source' => 0,
			'object' => 0,
			'value' => 1,
			'problem_time_from' => $filters['time_from'],
			'problem_time_till' => $filters['time_to'],
			'selectTags' => ['tag', 'value'],
			'selectSuppressionData' => ['maintenanceid', 'suppress_until'],
			'sortfield' => ['clock', 'eventid'],
			'sortorder' => 'DESC'
		]));

		$recovery_ids = [];
		foreach ($events as $event) {
			if ((int) ($event['r_eventid'] ?? 0) > 0) {
				$recovery_ids[(string) $event['r_eventid']] = true;
			}
		}

		$recovery_clocks = [];
		if ($recovery_ids) {
			$recoveries = $this->asArray(API::Event()->get([
				'output' => ['eventid', 'clock'],
				'eventids' => array_keys($recovery_ids),
				'preservekeys' => true
			]));
			foreach ($recoveries as $eventid => $event) {
				$recovery_clocks[(string) $eventid] = (int) $event['clock'];
			}
		}

		$result = [];
		foreach ($events as $event) {
			if (($filters['maintenance_mode'] ?? 'exclude') === 'exclude'
					&& ((int) ($event['suppressed'] ?? 0) === 1 || !empty($event['suppression_data']))) {
				continue;
			}
			$recovery_id = (string) ($event['r_eventid'] ?? '0');
			$recovery_clock = $recovery_clocks[$recovery_id] ?? null;
			$start = max($filters['time_from'], (int) $event['clock']);
			$end = $recovery_clock !== null
				? min($filters['time_to'], $recovery_clock)
				: $filters['time_to'];
			if ($end <= $start) {
				continue;
			}

			$result[] = [
				'eventid' => (string) $event['eventid'],
				'name' => (string) $event['name'],
				'severity' => (int) $event['severity'],
				'acknowledged' => (int) $event['acknowledged'] === 1,
				'start' => $start,
				'end' => $end,
				'duration' => $end - $start,
				'active' => $recovery_clock === null || $recovery_clock > $filters['time_to'],
				'category' => $this->problemCategory((string) $event['name'], $event['tags'] ?? [])
			];
		}

		return $result;
	}

	private function getResources(int $hostid, array $filters, array $host): array {
		$items = $this->asArray(API::Item()->get([
			'output' => ['itemid', 'name', 'key_', 'value_type', 'units', 'lastvalue', 'lastclock'],
			'hostids' => [$hostid],
			'monitored' => true,
			'filter' => ['value_type' => [0, 1, 3, 4]],
			'selectTags' => ['tag', 'value'],
			'preservekeys' => true
		]));

		$selected = ['cpu' => null, 'memory' => null, 'latency' => null, 'loss' => null,
			'uptime' => null, 'disks' => [], 'extra_metrics' => [], 'sensors' => [],
			'interfaces' => [], 'interface_summary' => $this->emptyInterfaceSummary(),
			'fortinet' => []];
		$ranks = ['cpu' => -1, 'memory' => -1, 'latency' => -1, 'loss' => -1, 'uptime' => -1];
		$disk_parts = [];
		$interface_parts = [];
		$sensors = [];
		$fortinet = [];
		$platform = $this->hostPlatform($host);

		foreach ($items as $itemid => $item) {
			$item['itemid'] = (string) $itemid;
			$interface_part = $this->interfacePart($item);
			if ($interface_part !== null) {
				[$interface_id, $interface_name, $metric] = $interface_part;
				if (!isset($interface_parts[$interface_id]['name'])
						|| strlen($interface_name) > strlen($interface_parts[$interface_id]['name'])) {
					$interface_parts[$interface_id]['name'] = $interface_name;
				}
				$existing_metric = $interface_parts[$interface_id]['metrics'][$metric] ?? null;
				if ($existing_metric === null
						|| (int) ($item['lastclock'] ?? 0) >= (int) ($existing_metric['lastclock'] ?? 0)) {
					$interface_parts[$interface_id]['metrics'][$metric] = $item;
				}
				continue;
			}
			$sensor_type = $this->sensorType($item);
			if ($sensor_type !== null) {
				$item['sensor_type'] = $sensor_type;
				$item['invert'] = false;
				$sensors[] = $item;
				continue;
			}
			if ($this->isFortinetMetric($item)) {
				$fortinet[] = $item;
			}
			$disk_part = $this->diskPart($item);
			if ($disk_part !== null) {
				[$filesystem, $metric, $invert] = $disk_part;
				$item['invert'] = $invert;
				$disk_parts[$filesystem][$metric] = $item;
				continue;
			}
			$classification = $this->classifyItem($item);
			if ($classification === null) {
				if (count($selected['extra_metrics']) < 8 && $this->isUsefulExtraMetric($item)) {
					$item['invert'] = false;
					$selected['extra_metrics'][] = $item;
				}
				continue;
			}
			[$type, $rank, $invert] = $classification;
			$item['invert'] = $invert;
			if ($rank > $ranks[$type]) {
				$selected[$type] = $item;
				$ranks[$type] = $rank;
			}
		}

		$trend_items = [];
		foreach (['cpu', 'memory', 'latency', 'loss'] as $type) {
			if ($selected[$type] !== null) {
				$trend_items[$selected[$type]['itemid']] = $selected[$type];
			}
		}
		foreach ($disk_parts as $parts) {
			$percent = $parts['pused'] ?? $parts['pfree'] ?? null;
			if ($percent !== null) {
				$trend_items[$percent['itemid']] = $percent;
			}
		}
		foreach ($selected['extra_metrics'] as $item) {
			$trend_items[$item['itemid']] = $item;
		}
		foreach ($sensors as $item) {
			if (in_array((int) ($item['value_type'] ?? -1), [0, 3], true)) {
				$trend_items[$item['itemid']] = $item;
			}
		}
		foreach ($interface_parts as $parts) {
			foreach ($parts['metrics'] ?? [] as $metric => $item) {
				if (in_array($metric, ['status', 'in', 'out', 'in_errors', 'out_errors',
						'in_discards', 'out_discards', 'speed'], true)) {
					$trend_items[$item['itemid']] = $item;
				}
			}
		}

		$trends = $this->getTrends(array_keys($trend_items), $filters);
		foreach (['cpu', 'memory', 'latency', 'loss', 'uptime'] as $type) {
			if ($selected[$type] !== null) {
				$selected[$type] = $this->decorateItem($selected[$type],
					$trends[$selected[$type]['itemid']] ?? [], $type);
			}
		}

		foreach (array_slice($disk_parts, 0, 20, true) as $filesystem => $parts) {
			$percent = $parts['pused'] ?? $parts['pfree'] ?? null;
			if ($percent === null) {
				continue;
			}
			$disk = $this->decorateItem($percent, $trends[$percent['itemid']] ?? [], 'disk');
			$disk['filesystem'] = $filesystem;
			$disk['total_bytes'] = $this->itemValue($parts['total'] ?? null);
			$disk['used_bytes'] = $this->itemValue($parts['used'] ?? null);
			$disk['free_bytes'] = $this->itemValue($parts['free'] ?? null);
			if ($disk['total_bytes'] !== null) {
				if ($disk['used_bytes'] === null && $disk['free_bytes'] !== null) {
					$disk['used_bytes'] = max(0.0, $disk['total_bytes'] - $disk['free_bytes']);
				}
				if ($disk['free_bytes'] === null && $disk['used_bytes'] !== null) {
					$disk['free_bytes'] = max(0.0, $disk['total_bytes'] - $disk['used_bytes']);
				}
			}
			$disk += $this->diskProjection($disk, $filters);
			$selected['disks'][] = $disk;
		}
		foreach ($selected['extra_metrics'] as &$extra) {
			$extra = $this->decorateItem($extra, $trends[$extra['itemid']] ?? [], 'extra');
		}
		unset($extra);

		foreach ($sensors as $sensor) {
			$decorated = $this->decorateItem($sensor, $trends[$sensor['itemid']] ?? [], 'extra');
			$decorated['display_status'] = $this->sensorDisplayStatus($decorated);
			$selected['sensors'][] = $decorated;
		}
		usort($selected['sensors'], static fn(array $a, array $b): int =>
			[strtolower($a['sensor_type']), strtolower($a['name'])]
			<=> [strtolower($b['sensor_type']), strtolower($b['name'])]);

		foreach ($interface_parts as $interface_id => $parts) {
			if (!$this->isPhysicalInterface((string) ($parts['name'] ?? ''))) {
				continue;
			}
			$selected['interfaces'][] = $this->buildInterfaceRow((string) $interface_id, $parts,
				$trends, $filters, $platform);
		}
		usort($selected['interfaces'], static fn(array $a, array $b): int =>
			strnatcasecmp($a['name'], $b['name']));
		$selected['interface_summary'] = $this->buildInterfaceSummary($selected['interfaces']);
		$selected['fortinet'] = $this->buildFortinetSummary($fortinet, $trends);

		return $selected;
	}

	private function getTrends(array $itemids, array $filters): array {
		if (!$itemids) {
			return [];
		}

		try {
			$rows = $this->asArray(API::Trend()->get([
				'output' => ['itemid', 'clock', 'num', 'value_min', 'value_avg', 'value_max'],
				'itemids' => $itemids,
				'time_from' => $filters['time_from'],
				'time_till' => $filters['time_to']
			]));
		}
		catch (\Throwable $e) {
			return [];
		}

		$grouped = [];
		foreach ($rows as $row) {
			$grouped[(string) $row['itemid']][] = $row;
		}
		return $grouped;
	}

	private function decorateItem(array $item, array $trends, string $metric_type): array {
		$invert = (bool) ($item['invert'] ?? false);
		[$warning, $critical] = $this->thresholds($metric_type, (string) ($item['units'] ?? ''));
		$points = [];
		$weighted_sum = 0.0;
		$total_num = 0;
		$maximum = null;
		$peak_clock = null;
		$warning_buckets = 0;
		$critical_buckets = 0;
		foreach ($trends as $trend) {
			$num = max(1, (int) $trend['num']);
			$average = (float) $trend['value_avg'];
			$max = (float) $trend['value_max'];
			if ($invert) {
				$average = 100.0 - $average;
				$max = 100.0 - (float) $trend['value_min'];
			}
			$points[] = ['clock' => (int) $trend['clock'], 'value' => $average];
			$weighted_sum += $average * $num;
			$total_num += $num;
			if ($maximum === null || $max > $maximum) {
				$maximum = $max;
				$peak_clock = (int) $trend['clock'];
			}
			if ($average >= $critical) {
				$critical_buckets++;
			}
			elseif ($average >= $warning) {
				$warning_buckets++;
			}
		}

		if (count($points) > self::MAX_TREND_POINTS) {
			usort($points, static fn(array $a, array $b): int => $a['clock'] <=> $b['clock']);
			$last_index = count($points) - 1;
			$sampled = [];
			for ($i = 0; $i < self::MAX_TREND_POINTS; $i++) {
				$sampled[] = $points[(int) round($i * $last_index / (self::MAX_TREND_POINTS - 1))];
			}
			$points = $sampled;
		}
		elseif (count($points) > 1) {
			usort($points, static fn(array $a, array $b): int => $a['clock'] <=> $b['clock']);
		}

		$current = is_numeric($item['lastvalue'] ?? null) ? (float) $item['lastvalue'] : null;
		if ($current !== null && $invert) {
			$current = 100.0 - $current;
		}
		$item['current'] = $current;
		$item['average'] = $total_num > 0 ? $weighted_sum / $total_num : $current;
		$item['maximum'] = $maximum ?? $current;
		$item['points'] = $points;
		$item['warning_threshold'] = $warning;
		$item['critical_threshold'] = $critical;
		$item['current_status'] = $this->metricStatus($item['current'], $warning, $critical);
		$item['period_status'] = $this->metricStatus($item['maximum'], $warning, $critical);
		$item['status'] = $item['period_status'];
		$item['peak_clock'] = $peak_clock;
		$item['warning_hours'] = $warning_buckets;
		$item['critical_hours'] = $critical_buckets;
		return $item;
	}

	private function metricStatus(?float $value, float $warning, float $critical): string {
		if ($value === null) {
			return 'unknown';
		}
		if (!is_finite($warning) || !is_finite($critical)) {
			return 'unrated';
		}
		return $value >= $critical ? 'critical' : ($value >= $warning ? 'warning' : 'ok');
	}

	private function diskProjection(array $disk, array $filters): array {
		$result = ['growth_per_day' => null, 'days_to_full' => null, 'full_at' => null,
			'bytes_growth_per_day' => null, 'bytes_to_target' => null, 'confidence' => null,
			'r2' => null, 'projection_status' => 'insufficient', 'targets' => []];
		$current = $disk['current'] ?? null;
		$total = $disk['total_bytes'] ?? null;
		$used = $disk['used_bytes'] ?? null;
		if ($current !== null && $total !== null && $used !== null && $current > 80.0) {
			$result['bytes_to_target'] = max(0.0, $used - ($total * 0.8));
		}
		$points = $disk['points'] ?? [];
		if (count($points) < 2) {
			return $result;
		}
		$first = reset($points);
		$last = end($points);
		$span_days = ((int) $last['clock'] - (int) $first['clock']) / 86400;
		if ($span_days < 6.0) {
			return $result;
		}

		$origin = (int) $first['clock'];
		$count = count($points);
		$sum_x = $sum_y = $sum_xx = $sum_xy = 0.0;
		foreach ($points as $point) {
			$x = ((int) $point['clock'] - $origin) / 86400;
			$y = (float) $point['value'];
			$sum_x += $x;
			$sum_y += $y;
			$sum_xx += $x * $x;
			$sum_xy += $x * $y;
		}
		$denominator = $count * $sum_xx - $sum_x * $sum_x;
		if (abs($denominator) < 0.000001) {
			return $result;
		}
		$growth = ($count * $sum_xy - $sum_x * $sum_y) / $denominator;
		$intercept = ($sum_y - $growth * $sum_x) / $count;
		$mean = $sum_y / $count;
		$total_variance = $residual_variance = 0.0;
		foreach ($points as $point) {
			$x = ((int) $point['clock'] - $origin) / 86400;
			$y = (float) $point['value'];
			$prediction = $intercept + $growth * $x;
			$total_variance += ($y - $mean) ** 2;
			$residual_variance += ($y - $prediction) ** 2;
		}
		$r2 = $total_variance > 0.000001 ? max(0.0, min(1.0, 1.0 - $residual_variance / $total_variance)) : 1.0;
		$result['growth_per_day'] = $growth;
		$result['bytes_growth_per_day'] = $total !== null ? $total * $growth / 100 : null;
		$result['r2'] = $r2;
		$result['confidence'] = $span_days >= 30 && $r2 >= 0.75 ? 'high'
			: ($span_days >= 14 && $r2 >= 0.5 ? 'medium' : 'low');

		if ($growth <= 0.01 || $r2 < 0.25 || $current === null) {
			$result['projection_status'] = 'stable';
			return $result;
		}
		$result['projection_status'] = 'forecast';
		foreach ([80, 90, 95, 100] as $target) {
			if ($current >= $target) {
				continue;
			}
			$days = ($target - $current) / $growth;
			if ($days <= 730) {
				$result['targets'][$target] = [
					'days' => $days,
					'clock' => $filters['time_to'] + (int) round($days * 86400)
				];
			}
		}
		if (isset($result['targets'][100])) {
			$result['days_to_full'] = $result['targets'][100]['days'];
			$result['full_at'] = $result['targets'][100]['clock'];
		}
		return $result;
	}

	private function classifyItem(array $item): ?array {
		$key = strtolower((string) $item['key_']);
		if ($key === 'system.cpu.util' || $key === 'fgate.cpu.util') {
			return ['cpu', 100, false];
		}
		if (strpos($key, 'system.cpu.util[') === 0) {
			return ['cpu', strpos($key, 'idle') !== false ? 70 : 60, strpos($key, 'idle') !== false];
		}
		if ($key === 'vm.memory.util' || $key === 'vm.memory.size[pused]' || $key === 'fgate.memory.util') {
			return ['memory', 100, false];
		}
		if (strpos($key, 'vm.memory.util[') === 0) {
			return ['memory', 80, false];
		}
		if ($key === 'icmppingsec' || strpos($key, 'icmppingsec[') === 0) {
			return ['latency', 100, false];
		}
		if ($key === 'icmppingloss' || strpos($key, 'icmppingloss[') === 0) {
			return ['loss', 100, false];
		}
		if ($key === 'system.uptime') {
			return ['uptime', 100, false];
		}
		return null;
	}

	private function interfacePart(array $item): ?array {
		$key = strtolower((string) ($item['key_'] ?? ''));
		$name = trim((string) ($item['name'] ?? ''));
		$metric = null;
		$patterns = [
			'in_errors' => ['net.if.in.errors[', 'net.if.in_errors[', 'fgate.netif.in_errors['],
			'out_errors' => ['net.if.out.errors[', 'net.if.out_errors[', 'fgate.netif.out_errors['],
			'in_discards' => ['net.if.in.discards[', 'net.if.in_discards['],
			'out_discards' => ['net.if.out.discards[', 'net.if.out_discards['],
			'admin_status' => ['net.if.adminstatus[', 'net.if.admin.status['],
			'status' => ['net.if.status[', 'fgate.netif.status['],
			'speed' => ['net.if.speed[', 'fgate.netif.speed['],
			'duplex' => ['net.if.duplex['],
			'in' => ['net.if.in[', 'fgate.netif.in['],
			'out' => ['net.if.out[', 'fgate.netif.out['],
			'type' => ['net.if.type[', 'fgate.netif.type[']
		];
		foreach ($patterns as $candidate => $prefixes) {
			foreach ($prefixes as $prefix) {
				if (strpos($key, $prefix) === 0) {
					$metric = $candidate;
					break 2;
				}
			}
		}
		if ($metric === null) {
			return null;
		}

		$tag_interface = '';
		$tag_description = '';
		foreach ($item['tags'] ?? [] as $tag) {
			$tag_name = strtolower(trim((string) ($tag['tag'] ?? '')));
			$tag_value = trim((string) ($tag['value'] ?? ''));
			if ($tag_name === 'interface' && $tag_value !== '') {
				$tag_interface = $tag_value;
			}
			elseif ($tag_name === 'description' && $tag_value !== '') {
				$tag_description = $tag_value;
			}
		}

		$argument = $key;
		if (preg_match('/\[(.*)\]$/', $key, $matches)) {
			$argument = trim($matches[1], " \t\n\r\0\x0B\"");
		}
		$id = $tag_interface !== '' ? 'tag:'.strtolower($tag_interface) : $argument;
		if ($tag_interface === '' && preg_match('/(?:^|\.)(\d+)$/', $argument, $matches)) {
			$id = $matches[1];
		}
		$interface_name = $name;
		if (preg_match('/^interface\s+\[?(.+?)\]?:\s*/i', $name, $matches)) {
			$interface_name = trim($matches[1]);
		}
		elseif (strpos($name, ':') !== false) {
			$interface_name = trim((string) strstr($name, ':', true));
		}
		$interface_name = preg_replace('/^interface\s+/i', '', $interface_name) ?: $interface_name;
		if ($tag_interface !== '') {
			$interface_name = $tag_interface;
			if ($tag_description !== '' && stripos($interface_name, $tag_description) === false) {
				$interface_name .= ' ('.$tag_description.')';
			}
		}
		return [$id, $interface_name !== '' ? $interface_name : $argument, $metric];
	}

	private function sensorType(array $item): ?string {
		$key = strtolower((string) ($item['key_'] ?? ''));
		$name = strtolower((string) ($item['name'] ?? ''));
		$text = $key.' '.$name;
		if (strpos($text, 'interface ') !== false || strpos($key, 'net.if.') === 0
				|| strpos($key, 'fgate.netif.') === 0) {
			return null;
		}
		$types = [
			'Temperatura' => ['temperature', 'temperatura', 'thermal', 'temp sensor', 'envmontemperature'],
			'Ventilador' => ['fan', 'ventilador'],
			'Fuente de poder' => ['power supply', 'power status', 'powersupply', 'psu'],
			'Voltaje' => ['voltage', 'voltaje'],
			'Corriente' => ['current sensor', 'amperage']
		];
		foreach ($types as $type => $words) {
			foreach ($words as $word) {
				if (strpos($text, $word) !== false) {
					return $type;
				}
			}
		}
		return null;
	}

	private function sensorDisplayStatus(array $sensor): string {
		$units = strtolower((string) ($sensor['units'] ?? ''));
		$value = $sensor['current'] ?? null;
		if ($value === null) {
			return 'unknown';
		}
		if ($sensor['sensor_type'] === 'Temperatura' && (strpos($units, 'c') !== false || $units === '')) {
			return $value >= 75 ? 'critical' : ($value >= 60 ? 'warning' : 'ok');
		}
		return 'unrated';
	}

	private function isPhysicalInterface(string $name): bool {
		$name = strtolower($name);
		foreach (['loopback', 'null', 'vlan', 'tunnel', 'gre', 'ipsec', 'ssl.root', 'docker',
				'veth', 'bridge', 'virtual-template', 'port-channel', 'bundle-ether'] as $virtual) {
			if (strpos($name, $virtual) !== false) {
				return false;
			}
		}
		return true;
	}

	private function buildInterfaceRow(string $id, array $parts, array $trends, array $filters,
			string $platform): array {
		$metrics = $parts['metrics'] ?? [];
		$decorated = [];
		foreach ($metrics as $metric => $item) {
			$item['invert'] = false;
			$decorated[$metric] = $this->decorateItem($item, $trends[$item['itemid']] ?? [], 'extra');
		}
		$status_item = $decorated['status'] ?? null;
		$status_value = $status_item['current'] ?? null;
		$status_key = strtolower((string) ($status_item['key_'] ?? ''));
		$is_fortinet_http = strpos($status_key, 'fgate.') === 0;
		$is_if_mib = strpos($status_key, 'ifoperstatus.') !== false;
		$is_windows_status = $platform === 'windows'
			|| (strpos($status_key, 'net.if.status[') === 0 && !$is_if_mib && !$is_fortinet_http);
		$traffic_active = (float) ($decorated['in']['current'] ?? 0) > 0
			|| (float) ($decorated['out']['current'] ?? 0) > 0;
		if ($status_value === null) {
			$is_up = $traffic_active;
			$status_source = $traffic_active ? 'Tráfico reciente' : 'Sin ítem de estado';
		}
		elseif ($is_windows_status) {
			$is_up = (int) round($status_value) === 2;
			$status_source = 'Estado Windows: '.(int) round($status_value);
		}
		else {
			$is_up = (int) round($status_value) === 1;
			$status_source = $is_fortinet_http ? 'Estado FortiGate HTTP' : 'Estado IF-MIB/SNMP';
		}
		$status_points = array_map(static fn(array $point): float => (float) $point['value'],
			$status_item['points'] ?? []);
		$changed = false;
		if (count($status_points) > 1) {
			$first = reset($status_points);
			foreach ($status_points as $point) {
				if (abs($point - $first) > 0.01 || abs($point - round($point)) > 0.01) {
					$changed = true;
					break;
				}
			}
		}
		$error_max = 0.0;
		$discard_max = 0.0;
		foreach (['in_errors', 'out_errors'] as $metric) {
			$error_max += (float) ($decorated[$metric]['maximum'] ?? 0);
		}
		foreach (['in_discards', 'out_discards'] as $metric) {
			$discard_max += (float) ($decorated[$metric]['maximum'] ?? 0);
		}
		$period_status = !$is_up ? 'down' : ($error_max > 0 || $discard_max > 0 ? 'errors'
			: ($changed ? 'changes' : 'stable'));
		return [
			'id' => $id,
			'name' => (string) ($parts['name'] ?? $id),
			'up' => $is_up,
			'status_source' => $status_source,
			'status_value' => $status_value,
			'usage' => $is_up ? 'USED' : 'FREE',
			'traffic_in' => $decorated['in'] ?? null,
			'traffic_out' => $decorated['out'] ?? null,
			'in_errors' => $decorated['in_errors'] ?? null,
			'out_errors' => $decorated['out_errors'] ?? null,
			'in_discards' => $decorated['in_discards'] ?? null,
			'out_discards' => $decorated['out_discards'] ?? null,
			'speed' => $decorated['speed'] ?? null,
			'error_max' => $error_max,
			'discard_max' => $discard_max,
			'changed' => $changed,
			'period_status' => $period_status,
			'time_from' => $filters['time_from'],
			'time_to' => $filters['time_to']
		];
	}

	private function hostPlatform(array $host): string {
		$inventory = is_array($host['inventory'] ?? null) ? $host['inventory'] : [];
		$text = strtolower(implode(' ', array_filter([
			(string) ($host['host'] ?? ''), (string) ($host['name'] ?? ''),
			(string) ($inventory['os'] ?? ''), (string) ($inventory['type'] ?? ''),
			implode(' ', array_column($host['hostgroups'] ?? [], 'name'))
		])));
		if (strpos($text, 'windows') !== false || strpos($text, 'microsoft') !== false) {
			return 'windows';
		}
		return 'network';
	}

	private function emptyInterfaceSummary(): array {
		return ['total' => 0, 'up' => 0, 'down' => 0, 'errors' => 0, 'changes' => 0,
			'traffic_in' => 0.0, 'traffic_out' => 0.0];
	}

	private function buildInterfaceSummary(array $interfaces): array {
		$summary = $this->emptyInterfaceSummary();
		$summary['total'] = count($interfaces);
		foreach ($interfaces as $interface) {
			$summary[$interface['up'] ? 'up' : 'down']++;
			if ($interface['error_max'] > 0 || $interface['discard_max'] > 0) {
				$summary['errors']++;
			}
			if ($interface['changed']) {
				$summary['changes']++;
			}
			$summary['traffic_in'] += (float) ($interface['traffic_in']['current'] ?? 0);
			$summary['traffic_out'] += (float) ($interface['traffic_out']['current'] ?? 0);
		}
		return $summary;
	}

	private function isFortinetMetric(array $item): bool {
		$key = strtolower((string) ($item['key_'] ?? ''));
		$name = strtolower((string) ($item['name'] ?? ''));
		return strpos($key, 'fgate.') === 0 || strpos($key, 'fgsys') !== false
			|| strpos($name, 'fortigate') !== false || strpos($name, 'fortinet') !== false
			|| strpos($key, 'ha.session.') === 0;
	}

	private function buildFortinetSummary(array $items, array $trends): array {
		$result = ['detected' => false, 'transport' => [], 'metrics' => []];
		$labels = [
			'fgate.device.model' => 'Modelo', 'fgate.device.serialnumber' => 'Número de serie',
			'fgate.device.firmware' => 'Firmware', 'fgate.device.vdom' => 'VDOM',
			'fgate.api.status' => 'Estado API', 'fgate.uptime' => 'Tiempo activo',
			'fgate.cpu.util' => 'CPU', 'fgate.memory.util' => 'Memoria', 'fgate.fs.util' => 'Disco',
			'fgate.session' => 'Sesiones', 'ha.session.count' => 'Sesiones HA',
			'fgsyscpuusage' => 'CPU', 'fgsysmemusage' => 'Memoria', 'fgsyssescount' => 'Sesiones'
		];
		foreach ($items as $item) {
			$key = strtolower((string) ($item['key_'] ?? ''));
			if (strpos($key, '.get_data') !== false || strpos($key, '.data_errors') !== false) {
				continue;
			}
			$result['detected'] = true;
			$result['transport'][strpos($key, 'fgate.') === 0 ? 'HTTP/API' : 'SNMP'] = true;
			$label = null;
			foreach ($labels as $pattern => $candidate) {
				if (strpos($key, $pattern) !== false) {
					$label = $candidate;
					break;
				}
			}
			if ($label === null && preg_match('/(firmware|serial|model|vdom|session|license|vpn|ha )/i',
					(string) ($item['name'] ?? ''))) {
				$label = (string) $item['name'];
			}
			if ($label === null) {
				continue;
			}
			$value = (string) ($item['lastvalue'] ?? '');
			$result['metrics'][] = [
				'label' => $label, 'value' => $value !== '' ? $value : 'Sin datos',
				'units' => (string) ($item['units'] ?? ''), 'key_' => (string) ($item['key_'] ?? '')
			];
			if (count($result['metrics']) >= 24) {
				break;
			}
		}
		$result['transport'] = array_keys($result['transport']);
		return $result;
	}

	private function diskPart(array $item): ?array {
		$key = (string) ($item['key_'] ?? '');
		if (!preg_match('/^vfs\.fs(?:\.dependent)?\.size\[(.*),(total|used|free|pused|pfree)\]$/i',
				$key, $matches)) {
			return null;
		}
		$filesystem = trim($matches[1], " \t\n\r\0\x0B\"");
		$metric = strtolower($matches[2]);
		return [$filesystem !== '' ? $filesystem : (string) ($item['name'] ?? 'Disco'), $metric,
			$metric === 'pfree'];
	}

	private function itemValue(?array $item): ?float {
		return $item !== null && is_numeric($item['lastvalue'] ?? null) ? (float) $item['lastvalue'] : null;
	}

	private function thresholds(string $metric_type, string $units): array {
		if ($metric_type === 'latency') {
			return strtolower($units) === 's' ? [0.1, 0.3] : [100.0, 300.0];
		}
		if ($metric_type === 'loss') {
			return [5.0, 20.0];
		}
		if (in_array($metric_type, ['cpu', 'memory', 'disk'], true)) {
			return [80.0, 90.0];
		}
		return [INF, INF];
	}

	private function isUsefulExtraMetric(array $item): bool {
		$key = strtolower((string) ($item['key_'] ?? ''));
		$name = strtolower((string) ($item['name'] ?? ''));
		$haystack = $key.' '.$name;
		if (!in_array((int) ($item['value_type'] ?? -1), [0, 3], true)) {
			return false;
		}
		foreach (['wmi.get', 'wmi.getall', '.get_data', 'discovery', 'raw data', 'json get'] as $excluded) {
			if (strpos($haystack, $excluded) !== false) {
				return false;
			}
		}
		foreach (['temperature', 'temperatura', 'fan', 'ventilador', 'session', 'sesion', 'power',
				'voltage', 'voltaje', 'connections', 'conexiones'] as $word) {
			if (strpos($haystack, $word) !== false) {
				return true;
			}
		}
		return false;
	}

	private function problemCategory(string $name, array $tags): string {
		foreach ($tags as $tag) {
			if (strtolower((string) ($tag['tag'] ?? '')) !== 'component') {
				continue;
			}
			$value = strtolower((string) ($tag['value'] ?? ''));
			$map = ['cpu' => 'CPU', 'memory' => 'Memoria', 'storage' => 'Almacenamiento',
				'network' => 'Red', 'service' => 'Servicios', 'hardware' => 'Hardware',
				'temperature' => 'Temperatura'];
			if (isset($map[$value])) {
				return $map[$value];
			}
		}

		$name = strtolower($name);
		$rules = [
			'CPU' => ['cpu', 'processor', 'load average'],
			'Memoria' => ['memory', 'memoria', 'swap'],
			'Almacenamiento' => ['disk', 'filesystem', 'file system', 'space', 'inode'],
			'Red' => ['interface', 'packet', 'icmp', 'network', 'latency', 'link down'],
			'Servicios' => ['service', 'servicio', 'process', 'daemon'],
			'Temperatura' => ['temperature', 'temperatura', 'thermal'],
			'Hardware' => ['fan', 'power supply', 'psu', 'hardware', 'sensor']
		];
		foreach ($rules as $category => $words) {
			foreach ($words as $word) {
				if (strpos($name, $word) !== false) {
					return $category;
				}
			}
		}
		return 'Otros';
	}

	private function buildProblemSummary(array $problems): array {
		$summary = $this->emptyProblemSummary();
		$summary['total'] = count($problems);
		$intervals = [];
		foreach ($problems as $problem) {
			if ($problem['active']) {
				$summary['active']++;
				$summary['active_max_severity'] = max($summary['active_max_severity'], $problem['severity']);
			}
			$summary['event_duration'] += $problem['duration'];
			$intervals[] = [$problem['start'], $problem['end']];
			$summary['max_severity'] = max($summary['max_severity'], $problem['severity']);
			$summary['categories'][$problem['category']] =
				($summary['categories'][$problem['category']] ?? 0) + 1;
		}
		foreach ($this->mergeIntervals($intervals) as [$start, $end]) {
			$summary['unique_duration'] += $end - $start;
		}
		$summary['duration'] = $summary['unique_duration'];
		$summary['health'] = $summary['active'] === 0 ? 'Normal'
			: ($summary['active_max_severity'] >= 4 ? 'Crítico'
				: ($summary['active_max_severity'] >= 2 ? 'Degradado' : 'Informativo'));
		arsort($summary['categories']);
		return $summary;
	}

	private function groupProblems(array $problems, array $filters): array {
		$groups = [];
		foreach ($problems as $problem) {
			$key = sha1(strtolower(trim($problem['name'])).'|'.$problem['category'].'|'.$problem['severity']);
			if (!isset($groups[$key])) {
				$groups[$key] = [
					'name' => $problem['name'], 'category' => $problem['category'],
					'severity' => $problem['severity'], 'count' => 0, 'active' => false,
					'first' => $problem['start'], 'last' => $problem['end'], 'total_duration' => 0,
					'first_start' => $problem['start'], 'last_start' => $problem['start'],
					'acknowledged' => true, 'flapping' => false
				];
			}
			$groups[$key]['count']++;
			$groups[$key]['active'] = $groups[$key]['active'] || $problem['active'];
			$groups[$key]['first'] = min($groups[$key]['first'], $problem['start']);
			$groups[$key]['last'] = max($groups[$key]['last'], $problem['end']);
			$groups[$key]['first_start'] = min($groups[$key]['first_start'], $problem['start']);
			$groups[$key]['last_start'] = max($groups[$key]['last_start'], $problem['start']);
			$groups[$key]['total_duration'] += $problem['duration'];
			$groups[$key]['acknowledged'] = $groups[$key]['acknowledged'] && $problem['acknowledged'];
			$groups[$key]['flapping'] = $groups[$key]['count'] >= 3;
		}
		$period_seconds = max(1, (int) $filters['time_to'] - (int) $filters['time_from']);
		$period_hours = $period_seconds / 3600;
		foreach ($groups as &$group) {
			$group['frequency_per_hour'] = $group['count'] / $period_hours;
			$group['frequency_per_day'] = $group['count'] * 86400 / $period_seconds;
			$group['recurrence_interval'] = $group['count'] > 1
				? (int) round(($group['last_start'] - $group['first_start']) / ($group['count'] - 1))
				: null;
			$group['average_duration'] = (int) round($group['total_duration'] / max(1, $group['count']));
			$group['flapping_level'] = $group['count'] >= 20 ? 'severe'
				: ($group['count'] >= 10 ? 'high' : ($group['count'] >= 3 ? 'moderate' : 'none'));
			$group['recommendation'] = $this->problemRecommendation($group['category'], $group['name']);
		}
		unset($group);
		$groups = array_values($groups);
		usort($groups, static function(array $a, array $b): int {
			return [$b['active'] ? 1 : 0, $b['severity'], $b['count'], $b['last']]
				<=> [$a['active'] ? 1 : 0, $a['severity'], $a['count'], $a['last']];
		});
		return $groups;
	}

	private function problemRecommendation(string $category, string $name): string {
		$name = strtolower($name);
		if ($category === 'Red' && (strpos($name, 'speed') !== false || strpos($name, 'velocidad') !== false)) {
			return 'Revisar autonegociación, cableado, driver y ahorro de energía de la interfaz.';
		}
		if ($category === 'Red') {
			return 'Revisar enlace físico, errores, descartes y estabilidad de la interfaz.';
		}
		if ($category === 'Almacenamiento') {
			return 'Liberar o ampliar capacidad y revisar el crecimiento del filesystem.';
		}
		if ($category === 'CPU' || $category === 'Memoria') {
			return 'Correlacionar el horario del pico con procesos, servicios y carga de trabajo.';
		}
		if ($category === 'Servicios') {
			return 'Revisar logs, dependencias, cuenta de servicio y política de reinicio.';
		}
		return 'Revisar el evento y correlacionarlo con otros problemas del mismo período.';
	}

	private function buildRecommendations(array $resources, array $problem_groups): array {
		$recommendations = [];
		foreach (['cpu' => 'CPU', 'memory' => 'memoria RAM'] as $key => $label) {
			$item = $resources[$key] ?? null;
			if ($item !== null && ($item['current_status'] ?? '') === 'critical') {
				$recommendations[] = 'El uso actual de '.$label.' está en nivel crítico; revise los procesos y la carga del host.';
			}
			elseif ($item !== null && ($item['period_status'] ?? '') === 'critical') {
				$recommendations[] = 'Se detectó un pico crítico de '.$label.'; correlacione su hora con servicios y procesos.';
			}
		}
		foreach ($resources['disks'] ?? [] as $disk) {
			if (($disk['current_status'] ?? '') === 'critical') {
				$recommendations[] = 'El disco '.$disk['filesystem'].' está en nivel crítico; libere o amplíe capacidad.';
			}
			elseif (isset($disk['targets'][90]) && $disk['targets'][90]['days'] <= 90) {
				$recommendations[] = 'El disco '.$disk['filesystem'].' podría alcanzar 90 % en '.
					(int) round($disk['targets'][90]['days']).' días; planifique capacidad.';
			}
		}
		foreach ($problem_groups as $group) {
			if (($group['flapping_level'] ?? 'none') === 'severe') {
				$recommendations[] = 'Flapping severo: '.$group['name'].' '.$group['recommendation'];
				break;
			}
		}
		if (($resources['latency'] ?? null) === null || ($resources['loss'] ?? null) === null) {
			$recommendations[] = 'Vincule ítems icmppingsec e icmppingloss para completar latencia y pérdida ICMP.';
		}
		return array_slice(array_values(array_unique($recommendations)), 0, 8);
	}

	private function mergeIntervals(array $intervals): array {
		if (!$intervals) {
			return [];
		}
		usort($intervals, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
		$merged = [array_shift($intervals)];
		foreach ($intervals as [$start, $end]) {
			$last = count($merged) - 1;
			if ($start <= $merged[$last][1]) {
				$merged[$last][1] = max($merged[$last][1], $end);
			}
			else {
				$merged[] = [$start, $end];
			}
		}
		return $merged;
	}

	private function detectDeviceProfile(array $host, array $resources): array {
		$inventory = is_array($host['inventory'] ?? null) ? $host['inventory'] : [];
		$groups = $host['hostgroups'] ?? [];
		$text = strtolower(implode(' ', array_filter([
			$host['host'] ?? '', $host['name'] ?? '', $inventory['os'] ?? '', $inventory['type'] ?? '',
			$inventory['model'] ?? '', implode(' ', array_column($groups, 'name'))
		])));
		if (!empty($resources['fortinet']['detected'])) {
			return ['type' => 'FortiGate', 'hint' => 'Vista de firewall: sistema, sesiones, HA, interfaces y seguridad.'];
		}
		if (!empty($resources['interfaces'])) {
			return ['type' => 'Dispositivo de red', 'hint' => 'Vista de red: interfaces físicas, tráfico, errores y sensores.'];
		}
		$profiles = [
			'Firewall' => ['firewall', 'fortigate', 'palo alto', 'sophos', 'checkpoint'],
			'Dispositivo de red' => ['switch', 'router', 'cisco', 'mikrotik', 'juniper', 'aruba',
				'redes', 'network', 'sw-'],
			'Virtualización' => ['vmware', 'esxi', 'hyper-v', 'proxmox', 'virtualization'],
			'UPS/Energía' => ['ups', 'apc', 'eaton'],
			'Servidor' => ['windows', 'linux', 'server', 'servidor']
		];
		foreach ($profiles as $label => $words) {
			foreach ($words as $word) {
				if (strpos($text, $word) !== false) {
					return ['type' => $label, 'hint' => $this->profileHint($label)];
				}
			}
		}
		return ['type' => $resources['cpu'] !== null ? 'Servidor/VM' : 'Host genérico',
			'hint' => 'Vista adaptada a los ítems numéricos disponibles.'];
	}

	private function profileHint(string $profile): string {
		$hints = [
			'Servidor' => 'Prioriza CPU, memoria, almacenamiento y servicios.',
			'Dispositivo de red' => 'Prioriza conectividad, latencia, pérdida y sensores.',
			'Firewall' => 'Prioriza conectividad, sesiones, recursos y alta disponibilidad.',
			'Virtualización' => 'Prioriza recursos del hipervisor, almacenamiento y máquinas virtuales.',
			'UPS/Energía' => 'Prioriza batería, carga, voltaje, temperatura y autonomía.'
		];
		return $hints[$profile] ?? 'Vista adaptada a los ítems disponibles.';
	}

	private function emptyProblemSummary(): array {
		return ['total' => 0, 'active' => 0, 'duration' => 0, 'unique_duration' => 0,
			'event_duration' => 0, 'max_severity' => 0, 'active_max_severity' => 0,
			'categories' => [], 'health' => 'Normal'];
	}

	private function asArray($value): array {
		return is_array($value) ? $value : [];
	}
}
