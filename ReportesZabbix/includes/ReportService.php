<?php

namespace Modules\AdvancedAvailabilityReports\Includes;

use API;
use DateTimeImmutable;
use DateTimeZone;

class ReportService {

	private const AVAILABILITY_KEYS = [
		'agent.ping',
		'icmpping'
	];

	public static function normalizeFilters(array $filters): array {
		$timezone = new DateTimeZone(date_default_timezone_get());
		$now = new DateTimeImmutable('now', $timezone);
		$period = in_array($filters['period'], ['daily', 'weekly', 'custom'], true)
			? $filters['period']
			: 'daily';

		if ($period === 'weekly') {
			$from = $now->modify('monday this week')->setTime(0, 0, 0);
			$to = $now;
			$date_to_display = $now->format('Y-m-d');
		}
		elseif ($period === 'custom' && self::validDate($filters['date_from'])
				&& self::validDate($filters['date_to'])) {
			$from = new DateTimeImmutable($filters['date_from'].' 00:00:00', $timezone);
			$to = (new DateTimeImmutable($filters['date_to'].' 00:00:00', $timezone))->modify('+1 day');
			$date_to_display = $filters['date_to'];
			if ($to <= $from) {
				$to = $from->modify('+1 day');
			}
			if ($to > $now) {
				$to = $now;
			}
			if ($from >= $to) {
				$from = $now->setTime(0, 0, 0);
				$to = $now;
				$date_to_display = $now->format('Y-m-d');
			}
		}
		else {
			$from = $now->setTime(0, 0, 0);
			$to = $now;
			$date_to_display = $now->format('Y-m-d');
		}

		$availability_modes = ['auto', 'agent', 'icmp', 'any', 'both'];
		$availability_mode = in_array($filters['availability_mode'] ?? 'auto', $availability_modes, true)
			? $filters['availability_mode']
			: 'auto';
		$company_name = self::normalizeHeading((string) ($filters['company_name'] ?? ''), '', 100);
		$report_title = self::normalizeHeading((string) ($filters['report_title'] ?? ''),
			'Monitoreo y disponibilidad de infraestructura', 140);

		return [
			'report_type' => $filters['report_type'] === 'managerial' ? 'managerial' : 'technical',
			'period' => $period,
			'groupid' => (int) $filters['groupid'],
			'hostid' => (int) $filters['hostid'],
			'availability_mode' => $availability_mode,
			'maintenance_mode' => ($filters['maintenance_mode'] ?? 'exclude') === 'include' ? 'include' : 'exclude',
			'sla_target' => max(0.0, min(100.0, (float) $filters['sla_target'])),
			'company_name' => $company_name,
			'report_title' => $report_title,
			'date_from' => $from->format('Y-m-d'),
			'date_to' => $date_to_display,
			'time_from' => $from->getTimestamp(),
			'time_to' => $to->getTimestamp()
		];
	}

	private static function normalizeHeading(string $value, string $default, int $maximum_length): string {
		$value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
		if ($value === '') {
			return $default;
		}
		return function_exists('mb_substr') ? mb_substr($value, 0, $maximum_length) : substr($value, 0, $maximum_length);
	}

	public function build(array $filters, bool $include_comparison = true): array {
		$choice_filters = $filters;
		$choice_filters['hostid'] = 0;
		$host_choices = $this->getHosts($choice_filters);
		$hosts = $filters['hostid'] > 0
			? array_intersect_key($host_choices, [(string) $filters['hostid'] => true])
			: $host_choices;
		$groups = $this->asArray(API::HostGroup()->get([
			'output' => ['groupid', 'name'],
			'with_monitored_hosts' => true,
			'preservekeys' => true,
			'sortfield' => 'name'
		]));

		if (!$hosts) {
			return $this->emptyResult($filters, $groups, $host_choices);
		}

		$hostids = array_keys($hosts);
		$triggers = $this->asArray(API::Trigger()->get([
			'output' => ['triggerid', 'description', 'priority', 'value', 'status'],
			'hostids' => $hostids,
			'monitored' => true,
			'selectTags' => ['tag', 'value'],
			'selectFunctions' => ['itemid'],
			'preservekeys' => true
		]));

		$itemids = [];
		foreach ($triggers as $trigger) {
			foreach ($trigger['functions'] ?? [] as $function) {
				$itemids[$function['itemid']] = true;
			}
		}

		$items = $itemids
			? $this->asArray(API::Item()->get([
				'output' => ['itemid', 'hostid', 'key_'],
				'itemids' => array_keys($itemids),
				'preservekeys' => true
			]))
			: [];

		$selected_triggers = [];
		$trigger_hosts = [];
		$all_trigger_hosts = [];
		$availability_sources = [];
		foreach (array_keys($hosts) as $hostid) {
			$availability_sources[$hostid] = ['agent' => false, 'icmp' => false];
		}
		$trigger_sources = [];
		foreach ($triggers as $triggerid => $trigger) {
			$hostid = $this->triggerHostId($trigger, $items);
			if ($hostid === null || !isset($hosts[$hostid])) {
				continue;
			}
			$all_trigger_hosts[$triggerid] = $hostid;

			if ($this->isAvailabilityTrigger($trigger, $items)) {
				$selected_triggers[$triggerid] = $trigger;
				$trigger_hosts[$triggerid] = $hostid;
				$trigger_sources[$triggerid] = $this->availabilitySources($trigger, $items);
				foreach ($trigger_sources[$triggerid] as $source) {
					$availability_sources[$hostid][$source] = true;
				}
			}
		}

		$health_by_host = $this->getCurrentHealth($all_trigger_hosts, $triggers, $hosts, $filters);
		$events = $this->getProblemEvents(array_keys($selected_triggers), $filters);
		$recovery_clocks = $this->getRecoveryClocks($events);
		$rows = $this->buildRows($hosts, $selected_triggers, $trigger_hosts, $events, $recovery_clocks,
			$health_by_host, $availability_sources, $trigger_sources, $filters);
		$summary = $this->buildSummary($rows, $filters['sla_target']);
		$comparison = null;
		if ($include_comparison && $filters['report_type'] === 'managerial') {
			$comparison = $this->buildComparison($filters, $summary);
		}

		return [
			'filters' => $filters,
			'groups' => $groups,
			'hosts_filter' => $host_choices,
			'rows' => $rows,
			'summary' => $summary,
			'comparison' => $comparison,
			'generated_at' => time(),
			'engine' => 'Zabbix API (independiente de PostgreSQL/MySQL)'
		];
	}

	private function getHosts(array $filters): array {
		$params = [
			'output' => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
			'monitored_hosts' => true,
			'selectHostGroups' => ['groupid', 'name'],
			'selectInterfaces' => ['interfaceid', 'type', 'main', 'useip', 'ip', 'dns', 'available', 'error'],
			'selectTags' => ['tag', 'value'],
			'preservekeys' => true,
			'sortfield' => 'name'
		];

		if ($filters['groupid'] > 0) {
			$params['groupids'] = [$filters['groupid']];
		}
		if ($filters['hostid'] > 0) {
			$params['hostids'] = [$filters['hostid']];
		}

		return $this->asArray(API::Host()->get($params));
	}

	private function isAvailabilityTrigger(array $trigger, array $items): bool {
		foreach ($trigger['functions'] ?? [] as $function) {
			if (!isset($items[$function['itemid']])) {
				continue;
			}

			$key = $items[$function['itemid']]['key_'];
			foreach (self::AVAILABILITY_KEYS as $availability_key) {
				if ($key === $availability_key || strpos($key, $availability_key.'[') === 0) {
					return true;
				}
			}
		}

		return false;
	}

	private function availabilitySources(array $trigger, array $items): array {
		$sources = [];
		foreach ($trigger['functions'] ?? [] as $function) {
			$key = strtolower((string) ($items[$function['itemid']]['key_'] ?? ''));
			if ($key === 'agent.ping' || strpos($key, 'agent.ping[') === 0) {
				$sources['agent'] = true;
			}
			if ($key === 'icmpping' || strpos($key, 'icmpping[') === 0) {
				$sources['icmp'] = true;
			}
		}
		return array_keys($sources);
	}

	private function triggerHostId(array $trigger, array $items): ?string {
		foreach ($trigger['functions'] ?? [] as $function) {
			if (isset($items[$function['itemid']]['hostid'])) {
				return (string) $items[$function['itemid']]['hostid'];
			}
		}

		return null;
	}

	private function getCurrentHealth(array $trigger_hosts, array $triggers, array $hosts, array $filters): array {
		$health = [];
		foreach (array_keys($hosts) as $hostid) {
			$health[$hostid] = ['active' => 0, 'max_severity' => 0, 'names' => []];
		}
		if (!$trigger_hosts) {
			return $health;
		}

		try {
			$problems = $this->asArray(API::Problem()->get([
				'output' => ['eventid', 'objectid', 'name', 'severity', 'suppressed'],
				'objectids' => array_keys($trigger_hosts),
				'source' => 0,
				'object' => 0,
				'recent' => false,
				'selectSuppressionData' => ['maintenanceid', 'suppress_until']
			]));
		}
		catch (\Throwable $e) {
			foreach ($triggers as $triggerid => $trigger) {
				$hostid = $trigger_hosts[$triggerid] ?? null;
				if ($hostid === null || (int) ($trigger['value'] ?? 0) !== 1
						|| ($filters['maintenance_mode'] === 'exclude'
							&& (int) ($hosts[$hostid]['maintenance_status'] ?? 0) === 1)) {
					continue;
				}
				$health[$hostid]['active']++;
				$health[$hostid]['max_severity'] = max($health[$hostid]['max_severity'],
					(int) ($trigger['priority'] ?? 0));
				if (count($health[$hostid]['names']) < 3) {
					$health[$hostid]['names'][] = (string) ($trigger['description'] ?? 'Problema activo');
				}
			}
			return $health;
		}

		foreach ($problems as $problem) {
			$triggerid = (string) ($problem['objectid'] ?? '');
			$hostid = $trigger_hosts[$triggerid] ?? null;
			if ($hostid === null || !isset($health[$hostid])) {
				continue;
			}
			if ($filters['maintenance_mode'] === 'exclude'
					&& ((int) ($problem['suppressed'] ?? 0) === 1 || !empty($problem['suppression_data']))) {
				continue;
			}
			$health[$hostid]['active']++;
			$health[$hostid]['max_severity'] = max($health[$hostid]['max_severity'],
				(int) ($problem['severity'] ?? 0));
			if (count($health[$hostid]['names']) < 3) {
				$health[$hostid]['names'][] = (string) ($problem['name'] ?? 'Problema activo');
			}
		}
		return $health;
	}

	private function getProblemEvents(array $triggerids, array $filters): array {
		if (!$triggerids) {
			return [];
		}

		$events = $this->asArray(API::Event()->get([
			'output' => ['eventid', 'objectid', 'clock', 'name', 'severity', 'acknowledged', 'r_eventid', 'suppressed'],
			'source' => 0,
			'object' => 0,
			'objectids' => $triggerids,
			'value' => 1,
			'problem_time_from' => $filters['time_from'],
			'problem_time_till' => $filters['time_to'],
			'selectSuppressionData' => ['maintenanceid', 'suppress_until'],
			'sortfield' => ['clock', 'eventid'],
			'sortorder' => 'ASC'
		]));

		if ($filters['maintenance_mode'] === 'exclude') {
			$events = array_values(array_filter($events, static function(array $event): bool {
				return (int) ($event['suppressed'] ?? 0) !== 1 && empty($event['suppression_data']);
			}));
		}
		return $events;
	}

	private function getRecoveryClocks(array $events): array {
		$eventids = [];
		foreach ($events as $event) {
			if ((int) $event['r_eventid'] > 0) {
				$eventids[$event['r_eventid']] = true;
			}
		}

		if (!$eventids) {
			return [];
		}

		$recoveries = $this->asArray(API::Event()->get([
			'output' => ['eventid', 'clock'],
			'eventids' => array_keys($eventids),
			'preservekeys' => true
		]));
		$clocks = [];
		foreach ($recoveries as $eventid => $event) {
			$clocks[$eventid] = (int) $event['clock'];
		}

		return $clocks;
	}

	private function buildRows(array $hosts, array $triggers, array $trigger_hosts, array $events,
			array $recovery_clocks, array $health_by_host, array $availability_sources,
			array $trigger_sources, array $filters): array {
		$period_seconds = max(1, $filters['time_to'] - $filters['time_from']);
		$host_trigger_ids = [];
		$current_by_source = [];
		$host_intervals = [];
		$host_incidents = [];
		foreach (array_keys($hosts) as $hostid) {
			$host_trigger_ids[$hostid] = ['agent' => [], 'icmp' => []];
			$current_by_source[$hostid] = ['agent' => false, 'icmp' => false];
			$host_intervals[$hostid] = ['agent' => [], 'icmp' => []];
			$host_incidents[$hostid] = ['agent' => [], 'icmp' => []];
		}

		foreach ($triggers as $triggerid => $trigger) {
			$hostid = $trigger_hosts[$triggerid];
			foreach ($trigger_sources[$triggerid] ?? [] as $source) {
				$host_trigger_ids[$hostid][$source][$triggerid] = true;
			}
		}

		foreach ($events as $event) {
			$triggerid = (string) $event['objectid'];
			if (!isset($trigger_hosts[$triggerid])) {
				continue;
			}

			$hostid = $trigger_hosts[$triggerid];
			$start = max($filters['time_from'], (int) $event['clock']);
			$recovery_clock = $recovery_clocks[$event['r_eventid']] ?? null;
			$end = $recovery_clock !== null
				? min($filters['time_to'], $recovery_clock)
				: $filters['time_to'];

			if ($end <= $start) {
				continue;
			}

			$incident = [
				'eventid' => $event['eventid'],
				'triggerid' => $triggerid,
				'name' => $event['name'],
				'severity' => (int) $event['severity'],
				'acknowledged' => (int) $event['acknowledged'] === 1,
				'start' => $start,
				'end' => $end,
				'duration' => $end - $start,
				'active' => $recovery_clock === null || $recovery_clock > $filters['time_to']
			];
			foreach ($trigger_sources[$triggerid] ?? [] as $source) {
				$host_intervals[$hostid][$source][] = [$start, $end];
				$host_incidents[$hostid][$source][] = $incident;
				if ($incident['active']) {
					$current_by_source[$hostid][$source] = true;
				}
			}
		}

		$rows = [];
		foreach ($hosts as $hostid => $host) {
			$host_groups = $host['hostgroups'] ?? $host['groups'] ?? [];
			$detected = $availability_sources[$hostid] ?? ['agent' => false, 'icmp' => false];
			$policy = $this->resolveAvailabilityPolicy($filters['availability_mode'], $detected);
			$agent_intervals = $this->mergeIntervals($host_intervals[$hostid]['agent']);
			$icmp_intervals = $this->mergeIntervals($host_intervals[$hostid]['icmp']);
			if ($policy === 'agent') {
				$merged = $agent_intervals;
				$incidents = $host_incidents[$hostid]['agent'];
				$indicator_count = count($host_trigger_ids[$hostid]['agent']);
				$current_problem = $current_by_source[$hostid]['agent'];
			}
			elseif ($policy === 'icmp') {
				$merged = $icmp_intervals;
				$incidents = $host_incidents[$hostid]['icmp'];
				$indicator_count = count($host_trigger_ids[$hostid]['icmp']);
				$current_problem = $current_by_source[$hostid]['icmp'];
			}
			elseif ($policy === 'both') {
				$merged = $detected['agent'] && $detected['icmp']
					? $this->intersectIntervals($agent_intervals, $icmp_intervals)
					: [];
				$incidents = $this->uniqueIncidents(array_merge($host_incidents[$hostid]['agent'],
					$host_incidents[$hostid]['icmp']));
				$indicator_count = $detected['agent'] && $detected['icmp']
					? count($host_trigger_ids[$hostid]['agent']) + count($host_trigger_ids[$hostid]['icmp'])
					: 0;
				$current_problem = $current_by_source[$hostid]['agent'] && $current_by_source[$hostid]['icmp'];
			}
			else {
				$merged = $this->mergeIntervals(array_merge($agent_intervals, $icmp_intervals));
				$incidents = $this->uniqueIncidents(array_merge($host_incidents[$hostid]['agent'],
					$host_incidents[$hostid]['icmp']));
				$indicator_count = count(array_unique(array_merge(array_keys($host_trigger_ids[$hostid]['agent']),
					array_keys($host_trigger_ids[$hostid]['icmp']))));
				$current_problem = $current_by_source[$hostid]['agent'] || $current_by_source[$hostid]['icmp'];
			}
			$downtime = 0;
			$max_outage = 0;
			foreach ($merged as [$start, $end]) {
				$duration = $end - $start;
				$downtime += $duration;
				$max_outage = max($max_outage, $duration);
			}

			$in_maintenance = (int) ($host['maintenance_status'] ?? 0) === 1;
			if ($filters['maintenance_mode'] === 'exclude' && $in_maintenance) {
				$current_problem = false;
			}
			$incident_count = count($merged);
			$availability = $indicator_count > 0
				? max(0.0, 100.0 * ($period_seconds - $downtime) / $period_seconds)
				: null;
			$health = $health_by_host[$hostid] ?? ['active' => 0, 'max_severity' => 0, 'names' => []];
			if ($health['active'] === 0) {
				$health['status'] = 'Normal';
				$health['level'] = 'ok';
			}
			elseif ($health['max_severity'] >= 4) {
				$health['status'] = 'Crítico';
				$health['level'] = 'critical';
			}
			elseif ($health['max_severity'] >= 2) {
				$health['status'] = 'Degradado';
				$health['level'] = 'warning';
			}
			else {
				$health['status'] = 'Informativo';
				$health['level'] = 'info';
			}

			$sla_target = $this->hostSlaTarget($host, $filters['sla_target']);
			$rows[] = [
				'hostid' => $hostid,
				'host' => $host['host'],
				'name' => $host['name'],
				'groups' => array_column($host_groups, 'name'),
				'interface' => $this->mainInterface($host['interfaces'] ?? []),
				'indicator_count' => $indicator_count,
				'availability_sources' => $detected,
				'availability_policy' => $policy,
				'availability' => $availability,
				'sla_target' => $sla_target,
				'downtime' => $downtime,
				'uptime' => max(0, $period_seconds - $downtime),
				'incident_count' => $incident_count,
				'mttr' => $incident_count > 0 ? (int) round($downtime / $incident_count) : 0,
				'max_outage' => $max_outage,
				'current_problem' => $current_problem,
				'in_maintenance' => $in_maintenance,
				'health' => $health,
				'incidents' => $incidents
			];
		}

		usort($rows, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
		return $rows;
	}

	private function resolveAvailabilityPolicy(string $requested, array $detected): string {
		if ($requested !== 'auto') {
			return $requested;
		}
		if (!empty($detected['agent'])) {
			return 'agent';
		}
		if (!empty($detected['icmp'])) {
			return 'icmp';
		}
		return 'any';
	}

	private function uniqueIncidents(array $incidents): array {
		$unique = [];
		foreach ($incidents as $incident) {
			$unique[(string) ($incident['eventid'] ?? count($unique))] = $incident;
		}
		return array_values($unique);
	}

	private function hostSlaTarget(array $host, float $fallback): float {
		foreach ($host['tags'] ?? [] as $tag) {
			$name = strtolower((string) ($tag['tag'] ?? ''));
			if (!in_array($name, ['report_sla', 'sla_target'], true)) {
				continue;
			}
			$value = str_replace(',', '.', (string) ($tag['value'] ?? ''));
			if (is_numeric($value)) {
				return max(0.0, min(100.0, (float) $value));
			}
		}
		return $fallback;
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

	private function intersectIntervals(array $left, array $right): array {
		$result = [];
		$i = 0;
		$j = 0;
		while ($i < count($left) && $j < count($right)) {
			$start = max($left[$i][0], $right[$j][0]);
			$end = min($left[$i][1], $right[$j][1]);
			if ($end > $start) {
				$result[] = [$start, $end];
			}
			if ($left[$i][1] < $right[$j][1]) {
				$i++;
			}
			else {
				$j++;
			}
		}
		return $this->mergeIntervals($result);
	}

	private function buildSummary(array $rows, float $sla_target): array {
		$evaluated = array_values(array_filter($rows, static fn(array $row): bool => $row['availability'] !== null));
		$total_downtime = array_sum(array_column($evaluated, 'downtime'));
		$total_incidents = array_sum(array_column($evaluated, 'incident_count'));
		$availability_sum = array_sum(array_column($evaluated, 'availability'));
		$compliant = count(array_filter($evaluated,
			static fn(array $row): bool => $row['availability'] >= ($row['sla_target'] ?? $sla_target)));

		return [
			'total' => count($rows),
			'evaluated' => count($evaluated),
			'without_indicator' => count($rows) - count($evaluated),
			'average_availability' => $evaluated ? $availability_sum / count($evaluated) : null,
			'compliant' => $compliant,
			'noncompliant' => count($evaluated) - $compliant,
			'total_downtime' => $total_downtime,
			'total_incidents' => $total_incidents,
			'compliance_rate' => $evaluated ? 100.0 * $compliant / count($evaluated) : null,
			'healthy' => count(array_filter($rows,
				static fn(array $row): bool => ($row['health']['level'] ?? 'ok') === 'ok')),
			'degraded' => count(array_filter($rows,
				static fn(array $row): bool => ($row['health']['level'] ?? 'ok') !== 'ok'))
		];
	}

	private function buildComparison(array $filters, array $current_summary): array {
		$duration = max(1, $filters['time_to'] - $filters['time_from']);
		$previous_filters = $filters;
		$previous_filters['time_to'] = $filters['time_from'];
		$previous_filters['time_from'] = $filters['time_from'] - $duration;
		$previous_filters['date_from'] = date('Y-m-d', $previous_filters['time_from']);
		$previous_filters['date_to'] = date('Y-m-d', max($previous_filters['time_from'],
			$previous_filters['time_to'] - 1));

		$previous = $this->build($previous_filters, false);
		$previous_average = $previous['summary']['average_availability'];
		$current_average = $current_summary['average_availability'];

		return [
			'time_from' => $previous_filters['time_from'],
			'time_to' => $previous_filters['time_to'],
			'average_availability' => $previous_average,
			'compliance_rate' => $previous['summary']['compliance_rate'],
			'delta_availability' => $current_average !== null && $previous_average !== null
				? $current_average - $previous_average
				: null,
			'delta_incidents' => $current_summary['total_incidents'] - $previous['summary']['total_incidents']
		];
	}

	private function mainInterface(array $interfaces): array {
		foreach ($interfaces as $interface) {
			if ((int) $interface['main'] === 1) {
				return [
					'address' => (int) $interface['useip'] === 1 ? $interface['ip'] : $interface['dns'],
					'type' => (int) $interface['type'],
					'available' => (int) $interface['available'],
					'error' => $interface['error']
				];
			}
		}
		return ['address' => '', 'type' => 0, 'available' => 0, 'error' => ''];
	}

	private function emptyResult(array $filters, array $groups, array $host_choices): array {
		return [
			'filters' => $filters,
			'groups' => $groups,
			'hosts_filter' => $host_choices,
			'rows' => [],
			'summary' => [
				'total' => 0, 'evaluated' => 0, 'without_indicator' => 0,
				'average_availability' => null, 'compliant' => 0, 'noncompliant' => 0,
				'total_downtime' => 0, 'total_incidents' => 0, 'compliance_rate' => null,
				'healthy' => 0, 'degraded' => 0
			],
			'comparison' => null,
			'generated_at' => time(),
			'engine' => 'Zabbix API (independiente de PostgreSQL/MySQL)'
		];
	}

	private static function validDate(string $date): bool {
		$value = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		return $value !== false && $value->format('Y-m-d') === $date;
	}

	private function asArray($value): array {
		return is_array($value) ? $value : [];
	}
}
