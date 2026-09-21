# TruckRoute 🚛🍃

> Sistema web de gestión de rutas, optimización logística y seguimiento en tiempo real para **Tres Reyes — Aceitunas y Encurtidos**.

[![Ver Demo / Aplicación](https://img.shields.io/badge/Demo-tres--reyes--logistica.page.gd-blue?style=for-the-badge&logo=googlechrome)](https://tres-reyes-logistica.page.gd)
[![Documentación Técnica](https://img.shields.io/badge/Documentación-GitHub%20Pages-green?style=for-the-badge&logo=github)](https://varelajoaquin2007-rgb.github.io/Documentacion_Tres-Reyes/)
[![Guía de Inicio Rápido](https://img.shields.io/badge/Gu%C3%ADa_R%C3%A1pida-QuickStart-orange?style=for-the-badge&logo=readme)](https://varelajoaquin2007-rgb.github.io/QuickStartGuide_TresReyes/)

---

## 📝 Descripción

**TruckRoute** es un sistema integral de planificación y control logístico desarrollado a medida para la empresa **Tres Reyes** (especializada en la producción y distribución de aceitunas y encurtidos). Reemplaza la gestión tradicional y manual de repartos (planillas aisladas, coordinación por WhatsApp y rutas fijas) por un flujo operativo 100% digitalizado.

El sistema automatiza el procesamiento de pedidos a partir de boletas en PDF, geocodifica las direcciones entregadas, agrupa los envíos por zonas geográficas, optimiza la secuencia de paradas de cada vehículo mediante algoritmos de ruteo y delega la navegación directa a Google Maps en los dispositivos de los repartidores.

---

## ✨ Características Principales

* 📄 **Carga e Ingesta Automática de Boletas:** Procesamiento y extracción de datos clave (cliente, dirección, peso de carga) directamente desde boletas en formato PDF.
* 📍 **Geocodificación y Agrupación por Zonas:** Conversión automática de direcciones en coordenadas de mapa y agrupamiento geográfico inteligente.
* 🧠 **Optimización de Rutas (Algoritmo de Vecino Más Cercano):** Cálculo automático del orden óptimo de paradas para minimizar la distancia recorrida y los tiempos de entrega.
* 📲 **Navegación Handoff a Google Maps:** Redirección directa desde la app web a la aplicación de Google Maps en el dispositivo del chofer, cargando automáticamente la ruta óptima completa con hasta 23 paradas.
* 🛰️ **Tracking y Monitoreo en Tiempo Real:** Visualización en vivo sobre el mapa centralizado de las posiciones de la flota en viaje.
* ⚠️ **Módulo de Alertas Automáticas:** Notificaciones en tiempo real por desviaciones de ruta no autorizadas o cierres de viaje.
* 👥 **Gestión de Roles y Permisos:** Control de acceso granular estructurado para tres perfiles (Administrador, Administrador Operativo y Repartidor).
* 📊 **Histórico de Envíos y Reportes:** Módulo de analítica sobre boletas procesadas, métricas de rendimiento e historial de viajes.

---

## 🛠️ Tecnologías Utilizadas

### **Backend & API**
* **PHP:** Arquitectura orientada a servicios RESTful para el procesamiento de lógica de negocio, parsing de PDFs, ruteo y gestión de alertas.
* **PHPMailer / Mailer:** Gestión de notificaciones por correo electrónico y recuperación de credenciales.

### **Base de Datos**
* **MySQL / MariaDB:** Almacenamiento relacional de usuarios, roles, catálogo de camiones, boletas, zonas geográficas, rutas e historial de posiciones.

### **Frontend & Panel Web**
* **HTML5, CSS3, JavaScript (ES6+):** Interfaz Web Responsiva (SPA - Single Page Application) adaptable a escritorios y dispositivos móviles.

### **Integraciones & Servicios de Mapas**
* **Google Maps JavaScript API:** Renderizado de mapas e interacción en tiempo real en el dashboard.
* **Google Geocoding API:** Procesamiento de conversión de direcciones físicas a coordenadas de latitud/longitud.
* **Google Maps URL Scheme (Handoff):** Integración nativa con la app de navegación externa para repartidores.

---

## 🏛️ Arquitectura del Proyecto

```text
├── index.html                  # Interfaz principal (Single Page Application)
├── config.php                  # Configuración de base de datos y parámetros del sistema
├── config_api.php              # Configuración de claves de API y endpoints externos
├── login.php                   # Autenticación y control de sesiones
├── recuperar.php               # Módulo de recuperación de contraseña por email
├── boletas.php                 # Subida, extracción de PDF y gestión de boletas
├── viajes.php                  # Armado, cálculo de rutas óptimas y asignación de choferes
├── tracking.php                # Receptor de posiciones e interfaz del mapa en vivo
├── alertas.php                 # Monitor de alertas por desvíos o eventos de ruta
├── repartidores.php            # Administración de flota de choferes
├── mailer.php                  # Servicio de envío de e-mails automatizados
├── lib_zonas.php               # Lógica de agrupación de direcciones por zonas geográficas
└── migraciones/                # Scripts SQL de estructura y parches de base de datos
    ├── migracion_cp.sql
    ├── migracion_destinos.sql
    ├── migracion_dia_hora.sql
    ├── migracion_geo_aproximada.sql
    ├── migracion_pospuesta.sql
    └── migracion_zonas.sql
```

---

## 🔐 Matriz de Permisos por Rol

| Módulo / Función | Administrador | Adm. Operativo | Repartidor |
| :--- | :---: | :---: | :---: |
| **Panel Dashboard & Métricas** | ✔️ | ✔️ | ❌ |
| **Ver Hoja de Ruta Propia** | ✔️ | ✔️ | ✔️ |
| **Carga e Ingesta de Boletas (PDF)** | ✔️ | ✔️ | ❌ |
| **Armado y Confirmación de Viajes** | ✔️ | ✔️ | ❌ |
| **Iniciar Viaje / Navegación externa** | ❌ | ❌ | ✔️ |
| **Tracking de Flota en Vivo** | ✔️ | ✔️ | ❌ |
| **Gestión de Alertas** | ✔️ | ✔️ | ❌ |
| **Configuración de Choferes y Camiones** | ✔️ | ✔️ | ❌ |

---

## 🚀 Instalación y Configuración Local

1. **Configurar el servidor local:**
   * Mover los archivos a la carpeta raíz de tu servidor local (ej. `htdocs` en XAMPP o `www` en WampServer).

2. **Configurar la base de datos:**
   * Crear una base de datos en MySQL/phpMyAdmin (ejemplo: `tres_reyes_db`).
   * Ejecutar en orden secuencial los scripts SQL ubicados en la carpeta de `migraciones/` para generar las tablas.

3. **Configurar Credenciales:**
   * Modificar el archivo `config.php` indicando host, usuario, contraseña y nombre de la base de datos local.
   * Modificar el archivo `config_api.php` para ingresar tu propia clave de **Google Maps API Key**.

4. **Ejecutar el proyecto:**
   * Abrir en tu navegador local la ruta correspondiente (ej. `http://localhost/truckroute`).

---

## 📚 Enlaces y Documentación Complementaria

* **Plataforma en vivo:** [https://tres-reyes-logistica.page.gd](https://tres-reyes-logistica.page.gd)
* **Documentación Técnica:** [Documentación del Proyecto en GitHub Pages](https://varelajoaquin2007-rgb.github.io/Documentacion_Tres-Reyes/)
* **Manual de Usuario:** [QuickStart Guide / Manual de Inicio Rápido](https://varelajoaquin2007-rgb.github.io/QuickStartGuide_TresReyes/)

---

## 👥 Equipo de Desarrollo

Proyecto desarrollado por el **Equipo N.º 5** — Instituto Leonardo Murialdo (7.º Informática):

* **Varela, Joaquín Ezequiel** - *Project Manager & Diseñador UX/UI*
* **Silva** - *Administrador de Base de Datos*
* **Traverso** - *Desarrollador Front/Back End*
* **Crupi** - *Desarrollador Front/Back End*
* **Garcia** - *Desarrollador Front End*
* **Gurschpon** - *Encargado Administrativo*
* **Rodriguez** - *Encargado Administrativo*

**Soporte Técnico & Contacto:** `proyectointermiami@gmail.com`

---
*TruckRoute v1.0 · Desarrollado para Tres Reyes — Aceitunas y Encurtidos*
