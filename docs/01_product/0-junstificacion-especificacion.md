# 📄 Inbiox — Documento de Justificación y Especificación

---

# 🧠 1. Introducción

**Inbiox** es una plataforma interna de gestión de atención al cliente basada en WhatsApp, diseñada para optimizar la operación de equipos multiagente mediante automatización, asignación inteligente y trazabilidad completa de las interacciones.

El sistema busca reemplazar herramientas limitadas actuales, centralizando la operación en una solución adaptable a las necesidades reales del negocio.

---

# 🎯 2. Justificación del proyecto

## 2.1 Problema actual

La operación actual presenta limitaciones:

* Falta de gestión estructurada de conversaciones
* Ausencia de asignación inteligente de agentes
* Dificultad para trabajar en equipo sobre chats
* Reportes limitados
* Escasa flexibilidad en automatización (bots y horarios)

Esto impacta directamente en:

* tiempos de respuesta
* calidad de atención
* eficiencia operativa
* trazabilidad de gestiones

---

## 2.2 Necesidad

Se requiere una solución que permita:

* Organizar la atención al cliente de forma estructurada
* Automatizar procesos repetitivos
* Mejorar la visibilidad operativa
* Escalar el equipo sin perder control

---

## 2.3 Objetivo del sistema

Construir una plataforma que:

* Centralice conversaciones de WhatsApp
* Permita gestionar tickets de atención
* Automatice asignaciones
* Incorpore chatbot configurable
* Proporcione métricas operativas claras

---

# 🏷️ 3. Justificación del nombre

## Nombre elegido: **Inbiox**

### Significado

* **Chat** → canal principal de interacción (WhatsApp)
* **Flow** → gestión estructurada del proceso de atención

### Beneficios del nombre

* Describe funcionalidad principal del sistema
* Es claro tanto para negocio como para desarrollo
* Escalable a futuro (posible evolución a producto)
* Fácil de utilizar en comunicación interna

Ejemplos de uso:

* “Asignalo en ChatFlow”
* “Revisá el ChatFlow”
* “Ese ticket está en ChatFlow”

---

# 🧩 4. Alcance del sistema

## 4.1 Canales

* Integración con WhatsApp (API oficial)
* Soporte para múltiples números

---

## 4.2 Conversaciones

* Gestión de conversaciones por contacto
* Historial completo de mensajes
* Canal continuo de comunicación

---

## 4.3 Tickets

* Unidad operativa principal
* Cada consulta/reclamo genera un ticket
* Estados del ticket:

  * Nuevo
  * En atención
  * Esperando cliente
  * Resuelto
  * Cerrado

---

## 4.4 Agentes

* Gestión de usuarios
* Asignación de tickets
* Trabajo colaborativo
* Definición de turnos

---

## 4.5 Asignación

* Automática según:

  * horario
  * carga de trabajo
  * skills (futuro)
* Manual (autoasignación)

---

## 4.6 Chatbot

* Flujos configurables tipo árbol
* Respuestas automáticas
* Derivación a agentes

---

## 4.7 Reglas

* Reglas por horario
* Respuestas fuera de horario
* Clasificación automática

---

## 4.8 Etiquetas y clasificación

* Categorías (controladas)
* Tags (libres)
* Filtrado y segmentación

---

## 4.9 Reportes

* Métricas operativas:

  * tiempo de primera respuesta
  * tiempo de resolución
  * tickets por agente
  * volumen de atención
* Exportación de datos

---

# 🧠 5. Modelo conceptual

## Entidades principales

* Contacto
* Conversación (thread)
* Ticket (unidad de trabajo)
* Mensaje
* Agente
* Evento

---

## Conceptos clave

### Conversación

Canal continuo con un cliente.

### Ticket

Unidad de gestión de una solicitud específica.

👉 Una conversación puede contener múltiples tickets.

---

# ⚙️ 6. Reglas operativas

## 6.1 Asignación

* Un ticket tiene un único agente responsable (owner)
* Puede haber múltiples participantes

---

## 6.2 Reapertura

* Un ticket cerrado puede:

  * reabrirse
  * generar uno nuevo (configurable)

---

## 6.3 Horarios

* Definición de turnos por agente
* Comportamiento diferenciado dentro/fuera de horario

---

## 6.4 Automatización

* Clasificación por chatbot
* Asignación automática basada en reglas

---

# 🧱 7. Arquitectura técnica

## Backend

* Symfony (PHP)

## Frontend

* Angular

## Base de datos

* MySQL

## Infraestructura

* AWS (objetivo)

## Tiempo real

* Mercure / WebSockets

---

# ⚡ 8. Características clave

* Multiagente
* Multi-segmento (clientes/contratistas)
* Escalable
* Modular
* Integrable

---

# ⚠️ 9. Restricciones

* Presupuesto limitado
* Desarrollo incremental
* Evitar sobreingeniería
* Prioridad en MVP funcional

---

# 🗺️ 10. Roadmap

## Fase 1

* Inbox básico
* Mensajería manual

## Fase 2

* Tickets
* Asignación

## Fase 3

* Chatbot
* Reglas

## Fase 4

* Reportes
* Optimización

---

# 🎯 11. Criterio de éxito

El sistema será exitoso si:

* Mejora los tiempos de respuesta
* Permite gestión ordenada de chats
* Facilita el trabajo en equipo
* Proporciona métricas claras
* Reduce carga manual

---

# 🧭 12. Conclusión

**Inbiox** se define como una solución estratégica para profesionalizar la atención al cliente mediante:

* estructuración del flujo de trabajo
* automatización inteligente
* visibilidad operativa

El sistema está diseñado para crecer de forma progresiva, adaptándose a las necesidades del negocio sin comprometer simplicidad ni control.
