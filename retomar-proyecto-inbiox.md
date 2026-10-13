# Retomar Inbiox: recuperar comprensión y control del proyecto

Este documento define una nueva etapa para el desarrollo de Inbiox. El objetivo no es agregar funcionalidades inmediatamente, sino reconstruir la comprensión del problema, del dominio y del código existente para recuperar control sobre la implementación antes de continuar desarrollando.

## Contexto

Inbiox comenzó como un producto propio orientado a gestionar atención y conversaciones, principalmente sobre WhatsApp.

Durante el desarrollo se fueron incorporando funcionalidades, reglas de negocio, arquitectura y tecnologías de manera incremental.

Como consecuencia, actualmente existe una cantidad considerable de código cuyo propósito, ubicación y relación con otras partes del sistema no siempre son claros para el desarrollador principal.

El proyecto no debe interpretarse como un fracaso ni necesariamente como un código que deba rehacerse.

El problema actual es principalmente de **comprensión y trazabilidad**.

Queremos llegar nuevamente a una situación en la que el desarrollador pueda comprender:

* qué problema resuelve cada parte importante del sistema;
* qué reglas de negocio existen;
* dónde están implementadas;
* qué entidades participan;
* cómo fluye una operación completa;
* por qué se tomó cada decisión importante;
* qué partes son necesarias;
* qué partes son accidentales o podrían simplificarse.

## Nuevo enfoque

Antes de continuar agregando funcionalidades, debemos recuperar una visión completa del sistema.

El orden de trabajo debe ser:

```text
Problema
    ↓
Proceso real
    ↓
Reglas de negocio
    ↓
Modelo de dominio
    ↓
Arquitectura
    ↓
Implementación existente
```

No debemos invertir este orden.

El código existente es evidencia de cómo se implementó el sistema, pero no debe considerarse automáticamente como la definición correcta del dominio.

Una implementación existente puede contener decisiones temporales, soluciones accidentales, complejidad innecesaria o reglas que fueron introducidas sin una definición explícita del problema.

## Objetivo

El objetivo de esta etapa es que el desarrollador pueda recuperar un modelo mental confiable de Inbiox.

Al finalizar esta etapa debería poder responder, para las funcionalidades principales:

* ¿Qué problema resuelve?
* ¿Quién la utiliza?
* ¿Cuál es el flujo?
* ¿Qué reglas de negocio intervienen?
* ¿Qué entidades participan?
* ¿Dónde está implementada?
* ¿Qué controlador inicia el flujo?
* ¿Qué servicios participan?
* ¿Qué repositorios o consultas utiliza?
* ¿Qué eventos, automatizaciones o procesos secundarios se ejecutan?
* ¿Qué datos modifica?
* ¿Qué otras funcionalidades dependen de ella?

## No modificar código inicialmente

Durante la primera etapa, el agente no debe realizar refactorizaciones ni cambios funcionales salvo que sean estrictamente necesarios para poder analizar el sistema.

No se debe:

* agregar funcionalidades;
* cambiar reglas de negocio;
* introducir nuevas tecnologías;
* reorganizar toda la arquitectura;
* crear abstracciones nuevas;
* realizar refactorizaciones generales;
* eliminar código simplemente porque parezca innecesario;
* cambiar entidades o relaciones sin una decisión explícita.

Primero debemos entender.

Después podremos decidir qué cambiar.

## Inventario del sistema

El primer trabajo consiste en construir un inventario comprensible del código existente.

No se busca documentar cada archivo.

Se busca identificar las partes que representan comportamiento relevante del producto.

Como mínimo, investigar:

* entidades principales;
* controladores;
* servicios;
* repositorios;
* comandos;
* eventos;
* listeners/subscribers;
* automatizaciones;
* procesos asíncronos, si existen;
* integraciones externas;
* autenticación y autorización;
* módulos funcionales principales.

Para cada elemento importante se debe identificar su propósito.

Ejemplo:

```text
TicketController
    ↓
recibe una acción relacionada con un ticket

TicketService
    ↓
contiene parte de la lógica del proceso

TicketRepository
    ↓
consulta/persiste tickets

Ticket
    ↓
representa el concepto de ticket dentro del dominio
```

La finalidad no es describir sintaxis, sino comportamiento.

## Reconstrucción de los flujos

Después del inventario, reconstruir los principales flujos de negocio de extremo a extremo.

Por ejemplo:

```text
Mensaje entrante
    ↓
Identificación del contacto
    ↓
Identificación/creación de conversación
    ↓
Creación o actualización del ticket
    ↓
Asignación
    ↓
Procesamiento de estado
    ↓
Respuesta del agente
    ↓
Cierre
```

El flujo real debe descubrirse leyendo el código.

No asumir que este ejemplo representa exactamente el comportamiento actual.

Para cada flujo importante se debe documentar:

1. Punto de entrada.
2. Pasos principales.
3. Entidades involucradas.
4. Reglas de negocio.
5. Persistencia.
6. Efectos secundarios.
7. Eventos o automatizaciones.
8. Resultado final.

## Separar dominio de implementación

Es fundamental distinguir entre:

### Regla de negocio

Una condición que existe porque el negocio necesita que el sistema se comporte de determinada manera.

Ejemplo:

> Un ticket no puede ser cerrado si todavía tiene una acción pendiente.

### Decisión técnica

Una forma particular de implementar una necesidad.

Ejemplo:

> Utilizamos un servicio para encapsular la operación de cierre.

### Detalle accidental

Una decisión que apareció durante el desarrollo pero que no representa una necesidad del producto.

Ejemplo:

> Existe una clase intermedia porque en determinado momento facilitó una implementación.

Estas tres cosas no deben tratarse como equivalentes.

## Detectar reglas de negocio implícitas

Una parte importante del trabajo es encontrar reglas que actualmente están escondidas dentro del código.

Por ejemplo:

```php
if ($ticket->getEstado() === 'ACTIVO') {
    ...
}
```

La pregunta no es solamente qué hace ese `if`.

Hay que determinar:

> ¿Por qué un ticket en estado ACTIVO debe comportarse de esta manera?

Si existe una razón de negocio, debemos documentarla.

Si no se puede determinar, marcarla como:

```text
Regla no confirmada
```

No inventar una explicación.

## Mapa de funcionalidades

Crear un mapa de las funcionalidades actuales de Inbiox.

Ejemplo conceptual:

```text
Inbiox
│
├── Conversaciones
│   ├── recepción de mensajes
│   ├── historial
│   └── respuesta
│
├── Tickets
│   ├── creación
│   ├── asignación
│   ├── estados
│   └── cierre
│
├── Agentes
│   ├── disponibilidad
│   ├── asignación
│   └── reasignación
│
├── Automatizaciones
│   ├── etiquetas
│   ├── reapertura
│   └── cierre
│
└── Reportes
    └── ...
```

Este árbol es solamente un ejemplo.

Debe construirse a partir del sistema real.

## Trazabilidad

Para las funcionalidades importantes, establecer una relación entre concepto de negocio y código.

Idealmente:

```text
Funcionalidad
    ↓
Regla de negocio
    ↓
Entidad / dominio
    ↓
Servicio
    ↓
Controlador / entrada
    ↓
Persistencia
```

El objetivo es que el desarrollador pueda recorrer el camino en ambos sentidos:

```text
"¿Cómo funciona esta funcionalidad?"
        ↓
código

"¿Dónde está implementada esta regla?"
        ↓
código

"¿Por qué existe este código?"
        ↓
regla / proceso / decisión
```

## Código generado o desarrollado con IA

No asumir que el código generado previamente por agentes es incorrecto.

Tampoco asumir que es correcto simplemente porque funciona.

Cada parte importante debe evaluarse según:

* qué hace;
* por qué existe;
* qué problema resuelve;
* qué dependencias tiene;
* si puede ser explicada por el desarrollador.

Si una parte no puede explicarse razonablemente, debe marcarse para revisión.

Principio:

> No aceptamos código que no podamos explicar.

## Complejidad

Identificar lugares donde exista complejidad que posiblemente no esté justificada.

Ejemplos:

* servicios que delegan innecesariamente en otros servicios;
* abstracciones sin una necesidad clara;
* múltiples capas para operaciones simples;
* eventos utilizados donde una llamada directa sería suficiente;
* dependencias que podrían eliminarse;
* estados o transiciones cuya razón no está clara;
* automatizaciones superpuestas;
* código duplicado.

No eliminar nada durante esta etapa.

Primero documentar:

```text
Complejidad detectada
¿Por qué existe?
¿Es necesaria?
¿Está respaldada por una regla de negocio?
¿Puede simplificarse?
```

## Resultado esperado

La primera entrega de esta etapa no debe ser código nuevo.

Debe ser una representación comprensible de cómo funciona actualmente Inbiox.

Como mínimo debería incluir:

1. Mapa funcional.
2. Principales entidades y relaciones.
3. Principales flujos.
4. Reglas de negocio identificadas.
5. Reglas de negocio no confirmadas.
6. Integraciones externas.
7. Arquitectura actual.
8. Puntos de complejidad.
9. Código o funcionalidades cuyo propósito no está claro.
10. Riesgos o inconsistencias detectadas.

## Después del análisis

Una vez completado el análisis, detenerse.

No comenzar automáticamente una refactorización.

El desarrollador debe revisar los resultados y decidir qué hacer.

Las posibles decisiones posteriores pueden ser:

```text
Mantener
    ↓
Simplificar
    ↓
Refactorizar
    ↓
Eliminar
    ↓
Rediseñar
```

También puede determinarse que una parte del sistema funciona correctamente y no necesita modificaciones.

## Nueva regla para futuras funcionalidades

Después de recuperar la comprensión del sistema, las nuevas funcionalidades deberían seguir este proceso:

```text
Problema
    ↓
Necesidad
    ↓
Regla de negocio
    ↓
Decisión de diseño
    ↓
Implementación
    ↓
Validación
```

No comenzar por:

```text
"Necesitamos agregar una entidad/servicio/controlador porque parece necesario."
```

Primero debe existir una razón funcional.

## Rol del agente

El agente debe actuar como herramienta de análisis e implementación.

Puede:

* explorar el código;
* construir mapas;
* detectar relaciones;
* explicar flujos;
* señalar inconsistencias;
* proponer simplificaciones;
* implementar cambios una vez aprobados.

No debe:

* inventar reglas de negocio;
* decidir silenciosamente cambios importantes;
* agregar arquitectura por anticipación;
* refactorizar todo el sistema sin autorización;
* asumir que código existente equivale a requisitos;
* ocultar incertidumbre.

Cuando no pueda determinar por qué existe una determinada regla o comportamiento, debe indicarlo explícitamente.

## Principio general

El objetivo de esta etapa no es que el código quede más moderno.

El objetivo es que el desarrollador vuelva a saber **qué tiene, por qué lo tiene y cómo funciona**.

Una vez recuperada esa comprensión, podremos decidir conscientemente qué merece ser conservado, simplificado, refactorizado o reemplazado.
