# GD-B2B Plugin — Storico Sviluppo

> Questo file documenta le modifiche apportate al plugin durante le sessioni di sviluppo,
> in modo da avere un contesto completo per sessioni future.

---

## Sessione 1 — Refactoring OOP + Miglioramenti (chat precedente)

**Chat ID**: `2d9dabea-d039-4121-8258-83e6d2182869`

### Analisi iniziale
- Plugin analizzato: struttura procedurale, 5 file principali, nessuna separazione asset/template.
- Proposti 20 miglioramenti, tutti approvati dall'utente (esclusa validazione algoritmica P.IVA/VIES, incluso check duplicato P.IVA).

### Refactoring OOP completo
Trasformazione da architettura procedurale a OOP con Singleton pattern.

**Nuove classi create:**
- `includes/class-gd-b2b-plugin.php` — Singleton centrale, orchestra tutti i moduli
- `includes/class-gd-b2b-roles.php` — Gestione ruoli B2B con cache transient
- `includes/class-gd-b2b-email.php` — Sistema email con rate limiting e template
- `includes/class-gd-b2b-piva-validator.php` — Validazione formato e duplicati P.IVA
- `includes/class-gd-b2b-audit-log.php` — Log attività/audit
- `includes/class-gd-b2b-registration.php` — Form registrazione frontend
- `includes/class-gd-b2b-checkout-fields.php` — Campi checkout WooCommerce Blocks
- `includes/class-gd-b2b-account-ui.php` — Banner e UI My Account
- `includes/class-gd-b2b-cart-blocker.php` — Blocco carrello per utenti pending
- `includes/admin/class-gd-b2b-admin.php` — Menu admin, asset, pagine
- `includes/admin/class-gd-b2b-admin-ajax.php` — Handler AJAX admin
- `includes/admin/class-gd-b2b-admin-settings.php` — Pagina configurazione
- `includes/admin/class-gd-b2b-clients-list-table.php` — WP_List_Table clienti B2B

**Asset separati:**
- `assets/css/admin-clients.css`, `admin-users-badge.css`, `frontend-account.css`, `frontend-registration.css`
- `assets/js/admin-clients.js`

**Template email:**
- `templates/emails/base.php` — Wrapper HTML branded
- `templates/emails/activation.php` — Email attivazione B2B
- `templates/emails/admin-notice.php` — Notifica admin nuova registrazione
- `templates/emails/user-confirmation.php` — Conferma registrazione al cliente

**Backward compatibility:**
- `includes/functions-core.php` riscritto come wrapper sottili verso le nuove classi OOP

### Bug risolti durante deploy
1. **PHP Parse Error** in `class-gd-b2b-registration.php`: apostrofi smart (U+2019) in stringhe single-quoted → fix: cambio a double quotes
2. **Fatal: redeclare `gd_b2b_role_label_for_slug()`**: duplicato in `clients-list-table.php` e `functions-core.php` → fix: rimosso da list-table
3. **Rate limit key mismatch**: codice cercava `rate_limit_seconds` ma la chiave era `rate_limit` → fix: corretta la chiave
4. **Email logo non visibile**: mismatch variabile `logo` vs `logo_url` tra email class e base.php + nessun logo configurato → fix: aggiunto campo "URL logo email" in settings + corretto template per accettare entrambe le chiavi

### Personalizzazioni email
- **Logo**: aggiunto campo configurabile in settings, priorità: setting > custom logo WP > site icon
- **Header**: rimosso gradient colorato, sostituito con sfondo bianco + riga gialla `#fcb900` di 2px
- **Body background**: cambiato a `#f5f5f4`

### Credenziali server
- **Host**: 162.19.153.199, porta 22
- **User SSH**: debian (con chiave `~/.ssh/id_ed25519`)
- **Sudo richiesto** per scrivere in `/var/www/vhosts/gustodiromagna.com/httpdocs/wp-content/plugins/gd-b2b/`
- **Ownership file**: `gustodiromagna.com:psacln`
- **PHP**: `/opt/plesk/php/8.2/bin/php`
- **WP-CLI**: `/usr/local/bin/wp` (eseguire come `sudo -u gustodiromagna.com`)
- **Restart PHP-FPM** (per OPcache): `sudo systemctl restart plesk-php82-fpm`

### Procedura deploy
```bash
# 1. Upload a temp
rsync -avz --delete -e "ssh -i ~/.ssh/id_ed25519" \
  "/Volumes/2TB GD 2/LAVORI/WEB/LOCAL/gustodiromagna.com/httpdocs/wp-content/plugins/gd-b2b/" \
  debian@162.19.153.199:/tmp/gd-b2b-deploy/

# 2. Copia in produzione + fix permessi + restart FPM
ssh -i ~/.ssh/id_ed25519 debian@162.19.153.199 \
  "sudo rsync -av --delete /tmp/gd-b2b-deploy/ /var/www/vhosts/gustodiromagna.com/httpdocs/wp-content/plugins/gd-b2b/ \
   && sudo chown -R gustodiromagna.com:psacln /var/www/vhosts/gustodiromagna.com/httpdocs/wp-content/plugins/gd-b2b/ \
   && sudo systemctl restart plesk-php82-fpm"
```

---

## Sessione 2 — Footer Email + Editor Template HTML + Fix

### Footer con indirizzo aziendale
**File modificati:**
- `templates/emails/base.php` — aggiunta variabile `$footer_text`, rendering con `nl2br(esc_html())` sotto il nome sito
- `includes/class-gd-b2b-email.php` — nuovo metodo `get_footer_text()`, passaggio `footer_text` a `build_branded_html()`, aggiornamento fallback inline
- `includes/admin/class-gd-b2b-admin-settings.php` — nuovo campo textarea "Testo footer email" con default:
  ```
  Gusto Di Romagna S.r.l.
  Viale Don Domenico Masi, 13 - 47924 - Miramare di Rimini, (RN)
  ```
- Default salvato nel DB via WP-CLI

### Pagina "Template Email" con editor HTML
**Nuova classe `includes/admin/class-gd-b2b-admin-email-templates.php`:**
- 4 template registrati: Base (wrapper), Attivazione B2B, Notifica Admin, Conferma Registrazione
- Ogni template ha placeholder dedicati (es. `{nome_cliente}`, `{url_negozio}`, `{piva}`, ecc.)
- Layout a 2 colonne: sidebar con lista template + main con editor
- `wp_editor()` con tab Visuale/HTML (TinyMCE + textarea)
- Box "Placeholder disponibili" con click-to-insert
- Pulsanti: Salva, Anteprima (popup), Invia email di test, Ripristina default
- Storage: `wp_option` `gd_b2b_email_templates` (array slug → HTML)
- 4 endpoint AJAX: `gd_b2b_save_email_template`, `gd_b2b_preview_email_template`, `gd_b2b_send_test_email`, `gd_b2b_reset_email_template`

**Nuovi asset:**
- `assets/css/admin-email-templates.css` — layout 2 colonne responsivo, placeholder buttons, notice
- `assets/js/admin-email-templates.js` — gestione editor, placeholder insert, AJAX save/preview/test/reset

**Modifica `render_template()` in `class-gd-b2b-email.php`:**
- Cerca prima nel DB (`gd_b2b_email_templates`) per template personalizzati
- Se trovato: sostituzione placeholder `{variabile}` → valore reale (con escaping: `esc_html()`, `esc_url()`, `nl2br()`)
- Se non trovato: rendering PHP file come prima (include + extract)
- Nuovi metodi: `render_file_template()` (bypass DB), `template_filename_to_slug()`, `render_db_template()`, `map_args_to_placeholders()`
- `send_html_email()` cambiato da `private` a `public` per permettere invio test

**Wiring:**
- `class-gd-b2b-plugin.php` — `require_once` del nuovo file admin
- `class-gd-b2b-admin.php` — proprietà `$email_templates`, init(), submenu "Template Email", enqueue CSS/JS/TinyMCE con `wp_localize_script` per traduzioni

### Placeholder per template

| Template | Placeholder | Descrizione |
|---|---|---|
| base | `{contenuto}` | Contenuto interno email |
| base | `{nome_sito}` | Nome del sito |
| base | `{url_logo}` | URL del logo |
| base | `{footer_text}` | Testo footer (indirizzo) |
| activation | `{nome_cliente}` | Nome del cliente |
| activation | `{url_negozio}` | URL pagina negozio |
| admin_notice | `{id_utente}` | ID utente WordPress |
| admin_notice | `{email_cliente}` | Email del cliente |
| admin_notice | `{ragione_sociale}` | Ragione sociale |
| admin_notice | `{piva}` | Partita IVA |
| admin_notice | `{sdi}` | Codice SDI |
| admin_notice | `{pec}` | Indirizzo PEC |
| admin_notice | `{referente}` | Nome referente |
| admin_notice | `{indirizzo}` | Indirizzo completo |
| admin_notice | `{telefono}` | Numero di telefono |
| admin_notice | `{url_modifica_utente}` | URL modifica utente |
| user_confirmation | `{nome_sito}` | Nome del sito |

### Fix: Default template con placeholder (non valori renderizzati)
**Problema**: `get_default_html()` rendeva i template PHP con valori dummy reali ("Mario Rossi", URL reali), quindi l'editor mostrava HTML già renderizzato senza `{placeholder}`. Di conseguenza, Anteprima e Test non potevano sostituire i placeholder (non c'erano!) e mostravano sempre lo stesso risultato generico.

**Soluzione — Approccio "marker + reverse-replace":**
1. `get_placeholder_render_args($slug)` — genera valori marker che sopravvivono a `esc_url()`, `esc_html()`, `esc_attr()`:
   - Testo: `GDB2B_NOME_CLIENTE`, `GDB2B_PIVA`, ecc.
   - URL: `https://gdb2b-placeholder.example/url-negozio`, ecc.
   - HTML: `<!--GDB2B:contenuto-->`
2. `render_file_template()` — nuovo metodo che rende sempre dal file PHP (bypass DB check)
3. Template PHP rendato con marker values
4. `get_marker_to_placeholder_map($slug)` — mappa marker → `{placeholder}` token
5. `str_replace()` per sostituire marker con placeholder nel risultato

**Risultato verificato:**
- base: 4/4 placeholder presenti nel default
- activation: 2/2 placeholder presenti
- admin_notice: 10/10 placeholder presenti
- user_confirmation: 0/1 (template statico, `{nome_sito}` disponibile per inserimento manuale)

**Pulizia DB**: rimossi template custom stale salvati durante test pre-fix (`delete_option('gd_b2b_email_templates')`)

### Fix: Riduzione spazio bianco sopra nelle email
**Problema**: troppo spazio bianco sopra il logo nelle email.
**Soluzione**: ridotto padding in `base.php` e fallback inline:
- Outer table: `padding:24px 12px` → `padding:8px 12px`
- Header cell: `padding:24px 24px 20px` → `padding:14px 24px 12px`

---

## Struttura file corrente del plugin

```
gd-b2b/
├── gd-b2b.php                          # Bootstrap, costanti, activation/deactivation
├── uninstall.php                        # Cleanup completo
├── readme.txt                           # Readme WordPress
├── CHANGELOG-DEV.md                     # ← Questo file
├── index.php                            # Silence
├── assets/
│   ├── css/
│   │   ├── admin-clients.css
│   │   ├── admin-email-templates.css
│   │   ├── admin-users-badge.css
│   │   ├── frontend-account.css
│   │   └── frontend-registration.css
│   └── js/
│       ├── admin-clients.js
│       └── admin-email-templates.js
├── includes/
│   ├── index.php                        # Silence
│   ├── functions-core.php               # Wrapper backward-compat
│   ├── class-gd-b2b-plugin.php          # Singleton principale
│   ├── class-gd-b2b-roles.php           # Ruoli + cache
│   ├── class-gd-b2b-email.php           # Email + template rendering + rate limit
│   ├── class-gd-b2b-piva-validator.php  # Validazione P.IVA
│   ├── class-gd-b2b-audit-log.php       # Audit log
│   ├── class-gd-b2b-registration.php    # Registrazione frontend
│   ├── class-gd-b2b-checkout-fields.php # Campi checkout Blocks
│   ├── class-gd-b2b-account-ui.php      # My Account UI
│   ├── class-gd-b2b-cart-blocker.php    # Blocco carrello
│   ├── cli-mail-test.php                # WP-CLI mail test
│   └── admin/
│       ├── class-gd-b2b-admin.php               # Menu, pagine, asset
│       ├── class-gd-b2b-admin-ajax.php           # AJAX handlers
│       ├── class-gd-b2b-admin-settings.php       # Pagina configurazione
│       ├── class-gd-b2b-admin-email-templates.php # Editor template email
│       └── class-gd-b2b-clients-list-table.php   # WP_List_Table clienti
└── templates/
    └── emails/
        ├── base.php                     # Wrapper HTML branded
        ├── activation.php               # Email attivazione
        ├── admin-notice.php             # Notifica admin
        └── user-confirmation.php        # Conferma registrazione
```

## Costanti del plugin
- `GD_B2B_VERSION` — versione corrente
- `GD_B2B_PATH` — path assoluto directory plugin
- `GD_B2B_URL` — URL directory plugin
- `GD_B2B_OPTION_SETTINGS` = `'gd_b2b_settings'` — opzione WP per configurazione
- `GD_B2B_META_STATUS` — user meta per stato B2B
- `GD_B2B_META_ACTIVATION_EMAIL_SENT` — contatore email inviate

## Opzioni WP utilizzate
- `gd_b2b_settings` — configurazione generale (ruoli approvabili + impostazioni email)
- `gd_b2b_email_templates` — template email personalizzati (array slug → HTML)
- `gd_b2b_audit_log` — log attività

---

## Sessione — Prezzi B2B (v2.1.0)

- Nuova classe `includes/class-gd-b2b-pricing.php`: pubblico per variante (`_gd_b2b_audience` = retail|b2b|both, fallback sul vecchio `_gd_b2b_only`), visibilità/acquistabilità, intervallo prezzi, dropdown, confezione predefinita, sconto extra per ruolo (opzione `gd_b2b_pricing`).
- Nuova pagina admin "GD B2B > Prezzi B2B" (`includes/admin/class-gd-b2b-admin-pricing.php`).
- Ruoli commerciali: vedono solo varianti `b2b`/`both`; clienti non B2B solo `retail`/`both`; admin e shop manager tutte.
- Sostituisce il mu-plugin `gd-variazioni-b2b.php` (rimosso).
