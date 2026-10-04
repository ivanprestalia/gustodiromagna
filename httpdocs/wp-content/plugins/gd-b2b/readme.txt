=== GD-B2B ===
Contributors: gustodiromagna
Tags: woocommerce, b2b, wholesale, registrazione aziende, fatturazione elettronica
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
Text Domain: gd-b2b

Plugin WooCommerce per la gestione completa del flusso B2B: registrazione aziende, approvazione manuale, campi fatturazione elettronica, blocco carrello per utenti in attesa e pannello amministrativo dedicato.

== Descrizione ==

**GD-B2B** aggiunge a WooCommerce un sistema completo per gestire clienti business-to-business.

I visitatori possono compilare un modulo di registrazione aziendale direttamente dalla pagina «Il mio account». Dopo l'invio, il profilo resta in stato *pending* (in attesa) finché un amministratore non assegna uno dei ruoli commerciali configurati. Solo allora il cliente potrà navigare il catalogo con i listini riservati e procedere agli acquisti.

Il plugin si integra con il sistema checkout a blocchi di WooCommerce e salva i campi di fatturazione elettronica italiana (P.IVA, Codice SDI, PEC) sia nei metadati utente sia nei checkout fields.

== Funzionalità ==

* **Registrazione B2B** — Modulo aziendale integrato nella pagina «Il mio account» di WooCommerce con campi ragione sociale, P.IVA, codice SDI, PEC, referente, indirizzo completo e consenso privacy.
* **Workflow di approvazione** — Gli utenti registrati restano in stato *pending* finché l'admin non assegna un ruolo commerciale (Rivenditore, Azienda sconto Base / Premium / Gold o ruoli personalizzati).
* **Blocco carrello e checkout** — Gli utenti in attesa non possono aggiungere prodotti al carrello né procedere al pagamento.
* **Campi checkout fatturazione elettronica** — P.IVA, Codice SDI e PEC salvati nei WooCommerce Checkout Fields (compatibilità blocchi) e nei meta utente.
* **Pannello admin «Clienti B2B»** — Elenco clienti con filtro per stato, ricerca libera, ragione sociale, email. Righe espandibili per modifica dati, cambio ruolo AJAX e invio/reinvio email attivazione.
* **Pagina Configurazione** — Selezione dei ruoli WordPress che il plugin riconosce come ruoli B2B approvabili.
* **Email transazionali** — Notifica admin alla registrazione, conferma ricezione richiesta al cliente, email di attivazione profilo B2B con template HTML branded (logo sito, gradiente testata).
* **Rate limiting email** — Conteggio invii email attivazione per utente, con feedback visivo nel pannello admin.
* **Audit log** — Registrazione azioni di cambio ruolo e invio email nel log errori PHP quando `WP_DEBUG_LOG` è attivo.
* **Badge «In attesa»** — Colonna «Richiesta B2B» nella lista utenti WordPress con badge colorati (pending / ruolo attivo).
* **Badge pending nel menu admin** — Contatore richieste in sospeso visibile nella voce di menu «GD B2B».
* **Controllo P.IVA duplicata** — Validazione formato 11 cifre e feedback in caso di email già registrata.
* **Endpoint «Richiesta B2B»** — Pagina dedicata nel menu «Il mio account» per utenti in attesa, con riepilogo stato e link modifica dati.
* **Uninstall completo** — Rimozione pulita di opzioni, meta utente e transient alla disinstallazione.

== Filtri e azioni disponibili ==

Il plugin espone diversi filtri per personalizzare il comportamento senza modificare il codice sorgente:

= gd_b2b_trade_role_slugs =
`(array)` — Slug dei ruoli WordPress riconosciuti come ruoli commerciali B2B. Per default legge dall'opzione `gd_b2b_settings[approvable_roles]`.

= gd_b2b_clients_list_user_ids =
`(array)` — ID utenti da mostrare nella lista «Clienti B2B» dell'admin. Permette di filtrare o estendere la lista programmaticamente.

= gd_b2b_company_email_logo_url =
`(string)` — URL del logo utilizzato nell'intestazione delle email HTML. Per default usa il logo del tema, il logo del sito o l'icona del sito.

= gd_b2b_activation_email_bcc_recipients =
`(array)` — Indirizzi email da aggiungere in Bcc all'email di attivazione profilo B2B (utile per copie conoscenza in fase di test o monitoraggio).

= gd_b2b_activation_email_headers =
`(array)` — Array completo degli header della mail di attivazione, modificabile subito prima della chiamata a `wp_mail()`.

= gd_b2b_edit_address_billing_hook_suffixes =
`(array)` — Suffissi hook per i campi indirizzo fatturazione nella pagina «Modifica indirizzo» di WooCommerce. Permette di aggiungere o rimuovere campi personalizzati.

= gd_b2b_admin_capability_candidates =
`(array)` — Lista ordinata di capability WordPress candidate per l'accesso al menu admin «GD B2B». La prima posseduta dall'utente corrente viene utilizzata.

= gd_b2b_admin_capability =
`(string)` — Capability di fallback per l'accesso admin se nessuna delle candidate viene trovata. Default: `manage_woocommerce`.

= gd_b2b_clients_list_php_fallback_max_site_users =
`(int)` — Soglia massima di utenti del sito per il fallback PHP nella lista clienti B2B. Oltre questa soglia il plugin utilizza solo query SQL.

= gd_b2b_clients_list_simple_scan_max_users =
`(int)` — Numero massimo di utenti per la scansione semplificata nella lista clienti. Ottimizzazione per siti con molti utenti.

= gd_b2b_clients_list_always_union_php_fallback =
`(bool)` — Se `true`, forza sempre l'unione del fallback PHP con i risultati della query SQL nella lista clienti, indipendentemente dal numero di utenti.

== Requisiti ==

* WordPress 6.0+
* WooCommerce 8.0+
* PHP 7.4+

== Installazione ==

1. Caricare la cartella `gd-b2b` nella directory `/wp-content/plugins/`.
2. Attivare il plugin dal menu «Plugin» di WordPress.
3. Verificare che WooCommerce sia attivo e configurato.
4. Accedere a **GD B2B → Configurazione** per selezionare i ruoli commerciali approvabili.
5. Il modulo «Registrazione azienda» apparirà automaticamente nella pagina «Il mio account» per i visitatori non autenticati.

== Domande frequenti ==

= Posso aggiungere ruoli personalizzati? =
Sì. Dalla pagina **GD B2B → Configurazione** puoi selezionare qualsiasi ruolo WordPress (creato ad esempio con User Role Editor) come ruolo B2B approvabile.

= Come funziona il blocco acquisti? =
Gli utenti con stato *pending* non possono aggiungere prodotti al carrello né completare il checkout. Il blocco viene rimosso automaticamente quando l'admin assegna un ruolo commerciale.

= Le email sono personalizzabili? =
I template HTML si trovano in `templates/emails/`. Puoi sovrascriverli nel tema figlio copiandoli in `yourtheme/gd-b2b/emails/`. Il logo è configurabile tramite il filtro `gd_b2b_company_email_logo_url`.

== Screenshot ==

1. Modulo registrazione aziendale nella pagina «Il mio account».
2. Pannello admin «Clienti B2B» con filtri e azioni.
3. Modal conferma cambio ruolo con invio email.
4. Email HTML di attivazione profilo B2B.

== Changelog ==

= 2.1.0 =
* Nuova sezione "Prezzi B2B": pubblico per variante (privati / aziende / tutti), prezzi di listino e sconto extra per livello (premium, gold...). Sostituisce il mu-plugin gd-variazioni-b2b.

= 2.0.0 =
* Refactoring OOP completo dell'architettura plugin.
* CSS e JS estratti in file statici separati (`assets/css/`, `assets/js/`).
* Template email HTML in file PHP dedicati (`templates/emails/`).
* Protezione CSRF migliorata con nonce su tutti i form e le azioni AJAX.
* Rate limiting email attivazione con conteggio invii per utente.
* Ottimizzazione query lista clienti con transient e cache.
* Paginazione SQL nativa nella lista «Clienti B2B».
* Bulk actions nel pannello admin.
* Audit log azioni admin (cambio ruolo, invio email) nel debug log.
* Badge contatore pending nel menu admin «GD B2B».
* Pagina Configurazione per selezione ruoli approvabili dall'admin.
* Controllo P.IVA: validazione formato 11 cifre e feedback duplicati.
* Rimosso utilizzo di `$GLOBALS` per il passaggio dati registrazione.
* Uninstall completo: pulizia opzioni, meta utente e transient.
* Filtro `gettext` ottimizzato con early return per performance.
* Label ruoli centralizzate con funzione dedicata `gd_b2b_role_label_for_slug()`.

= 1.2.8 =
* Versione precedente (architettura procedurale).
