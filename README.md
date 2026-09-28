# Reportes de monitoreo y disponibilidad 1.0

Módulo inicial para Zabbix 7.0 LTS y 7.4. Genera una vista técnica o gerencial de disponibilidad por host, exporta CSV y permite imprimir o guardar la vista como PDF desde el navegador.

El encabezado permite definir el nombre de la empresa o institución y personalizar el título del reporte. Ambos valores se conservan en el navegador y se aplican al reporte general, al detalle por host y a la impresión/PDF.

## Perfil de equipos de red

- Detecta interfaces descubiertas por las plantillas oficiales mediante claves `net.if.*` y `fgate.netif.*`.
- Muestra interfaces físicas con estado UP/DOWN, uso, tráfico, velocidad, errores, descartes y cambios durante el período.
- Interpreta correctamente los estados Windows (`Connected = 2`), IF-MIB/SNMP (`up = 1`) y FortiGate HTTP.
- Consolida los ítems de tráfico, estado, velocidad y errores usando la etiqueta `interface`, evitando NIC duplicadas.
- Excluye de las gráficas los ítems maestros WMI, descubrimientos, JSON y otros datos de recolección no numéricos.
- Consolida temperatura, ventiladores, fuentes y voltaje en una tabla Actual/Promedio/Máximo, sin una gráfica por sensor.
- Reconoce Cisco IOS por SNMP y FortiGate por SNMP o HTTP a partir de los ítems que Zabbix ya recopila.
- Para FortiGate presenta firmware, modelo, serie, VDOM, sesiones, HA, VPN y licencias cuando existen los ítems.

El módulo no se conecta directamente al equipo ni almacena credenciales: utiliza los datos visibles para el usuario actual mediante la API de Zabbix.

# Instalación

1. Copiar `ReportesZabbix.zip` al servidor Zabbix.
 ```bash
cd /usr/share/zabbix/ui/modules
```
3. Descomprimirlo dentro del directorio de módulos del frontend.
```bash
unzip -o ReportesZabbix.zip
```

3. Ingresar al frontend como Super Admin.
4. Ir a **Administración → General → Módulos**.
5. Seleccionar **Escanear directorio**.
6. Si ya estaba habilitado, deshabilitarlo y volverlo a habilitar para recargar el manifiesto.
7. Habilitar **Reportes de monitoreo y disponibilidad**.
8. Abrir **Reportes → Disponibilidad avanzada**.

No se requieren cambios ni credenciales adicionales de PostgreSQL o MySQL.

## Fuente de datos

Esta versión usa exclusivamente la API interna de Zabbix. Por ello funciona de la misma forma con PostgreSQL, PostgreSQL + TimescaleDB, MySQL y MariaDB.

La disponibilidad reconoce exclusivamente triggers que utilicen estos ítems:

- `agent.ping`
- `icmpping`

Los problemas de CPU, memoria, discos, red, servicios, temperatura y hardware se presentan como salud operativa, sin reducir el SLA.

## Cálculo

`Disponibilidad = (segundos del periodo - segundos de caída) / segundos del periodo * 100`

Los incidentes simultáneos de un mismo host se unen antes del cálculo, evitando duplicar el tiempo de caída.

## Umbrales visuales predeterminados

- CPU, memoria y disco: advertencia desde 80 %, crítico desde 90 %.
- Pérdida ICMP: advertencia desde 5 %, crítico desde 20 %.
- Latencia ICMP: advertencia desde 100 ms, crítico desde 300 ms.

Los umbrales son visuales y no crean ni modifican triggers de Zabbix.


## Políticas de disponibilidad

- **Automático:** utiliza Agent cuando existe; si no, utiliza ICMP.
- **Solo Agent:** calcula únicamente con triggers asociados a `agent.ping`.
- **Solo ICMP:** calcula únicamente con triggers asociados a `icmpping`.
- **Caída si falla cualquiera:** une los intervalos de Agent e ICMP.
- **Caída solo si fallan ambos:** descuenta únicamente la intersección de ambos tipos de caída; requiere las dos fuentes.

## Mantenimientos y supresión

La opción **Excluir suprimidos** elimina del cálculo los eventos que `event.get` devuelve actualmente como suprimidos o con `suppression_data`. Además identifica hosts que se encuentran en mantenimiento. La reconstrucción exacta de mantenimientos históricos ya finalizados y recurrentes requiere una futura integración con calendarios de mantenimiento o SLA de Zabbix.

## Límites conocidos y próximas integraciones

- Reconstrucción histórica exacta de mantenimientos planificados ya finalizados.
- Calendarios 8x5, 16x7 y personalizados.
- Envío automático de PDF por correo.
- Perfiles con KPIs específicos por fabricante para switches, routers, firewalls, UPS y VMware.
- Personalización de logotipo y datos del cliente.
- Integración con Servicios/SLA de Zabbix.

## Notas de rendimiento

El detalle de recursos y problemas se consulta solo al abrir un host. La comparación gerencial ejecuta una segunda consulta equivalente para el período anterior. Para entornos con miles de hosts se recomienda filtrar por grupo y mantener habilitada la retención de trends.
