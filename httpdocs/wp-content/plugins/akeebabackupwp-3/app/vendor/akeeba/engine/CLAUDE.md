# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Akeeba Engine is a site backup engine library written in PHP, used by Akeeba Backup (Joomla) and Akeeba Solo (standalone). It handles file scanning, database dumping, archive creation, and post-processing (uploading to cloud storage). The engine is designed to run in stepped execution across multiple HTTP requests, serializing/deserializing its state between steps.

## Commands

```bash
# Run tests
vendor/bin/phpunit Test/

# Run a single test file
vendor/bin/phpunit Test/Driver/FixMySQLHostnameTest.php

# Run Rector (code modernization, targets PHP 8.4)
vendor/bin/rector process engine/
```

## PHP Requirements

- Minimum PHP 7.4, supports PHP 8.x
- Required extensions: fileinfo, json, mbstring
- No `declare(strict_types=1)` is used in engine code

## Code Conventions

- All PHP files in `engine/` must have the `defined('AKEEBAENGINE') || die();` guard after the namespace declaration
- All files carry a GPL-3.0-or-later license header block
- PSR-4 autoloading: `Akeeba\Engine\` maps to `engine/`
- The `#[\AllowDynamicProperties]` attribute is used on classes that need dynamic properties

## Architecture

### Entry Points

- **`Factory`** (`engine/Factory.php`) — Abstract static service locator. All engine components are accessed through Factory methods (`getConfiguration()`, `getArchiverEngine()`, `getDumpEngine()`, `getKettenrad()`, `getLog()`, etc.). Manages serialization/deserialization of engine state between backup steps.

- **`Platform`** (`engine/Platform.php`) — Singleton that discovers and loads the platform connector (Joomla, WordPress, Solo, etc.). Platform connectors implement `PlatformInterface` and provide platform-specific storage, configuration, and directory paths.

### Backup Lifecycle (Part State Machine)

All engine components inherit from **`Base\Part`** (`engine/Base/Part.php`), which defines a state machine:

`STATE_INIT → STATE_PREPARED → STATE_RUNNING → STATE_POSTRUN → STATE_FINISHED` (or `STATE_ERROR`)

Subclasses implement `_prepare()`, `_run()`, and `_finalize()`. The public API is `tick()`, which advances through states. Each `tick()` is meant to run within a single PHP execution step.

### Kettenrad (Main Controller)

**`Core\Kettenrad`** (`engine/Core/Kettenrad.php`) orchestrates the entire backup by running domain objects in sequence. It extends `Part` and manages the transition between backup domains.

### Backup Domains (`Core/Domain/`)

Each domain handles one phase of the backup:
- **Init** — Initialization and setup
- **Pack** — File scanning and archiving
- **DB** — Database dump
- **Installer** — Adding installer files to the archive
- **Finalization** — Post-processing, quotas, email notifications (via `Finalizer/` strategies implementing `FinalizerInterface`)

### Pluggable Engine Types

Engines are selected via configuration keys (e.g., `akeeba.advanced.archiver_engine`) and instantiated by Factory:

- **Archiver** (`engine/Archiver/`) — Archive formats: JPA, JPS (encrypted), ZIP, Zipnative, DirectFTP, DirectSFTP
- **Dump** (`engine/Dump/`) — Database dump engines: Native (MySQL, PostgreSQL)
- **Scan** (`engine/Scan/`) — File scanners: Large, Smart
- **Postproc** (`engine/Postproc/`) — Upload destinations: S3, Azure, Dropbox, Box, Backblaze, Google Drive, OneDrive, etc. Implement `PostProcInterface`.

### Driver (Database Abstraction)

`engine/Driver/` provides database drivers (Mysqli, Pdomysql, Postgresql, Sqlite) extending `Driver\Base`, with query builders in `Driver/Query/`.

### Configuration

**`Configuration`** (`engine/Configuration.php`) — Registry-style configuration with dot-notation namespace keys (e.g., `akeeba.advanced.archiver_engine`). Default values come from JSON files in `engine/Core/`.

### Filter System

`engine/Filter/` implements include/exclude filtering for files, directories, database tables, and table data. `Filter\Base` is the base class. Stack filters in `Filter/Stack/` provide default exclusion rules. `Core\Filters` aggregates all active filters.

## Directory Layout

```
engine/          # Production source code (Akeeba\Engine namespace)
Test/            # PHPUnit tests
dev_platform/    # Development platform implementation (not shipped)
connector_development/  # Connector dev tools (not shipped)
tools/           # Utility scripts (not shipped)
```
