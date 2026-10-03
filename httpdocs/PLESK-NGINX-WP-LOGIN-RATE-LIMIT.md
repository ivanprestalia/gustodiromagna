# Nginx: rate limit su `wp-login.php` (Plesk Obsidian) + checklist rimozione LLAR

Questa guida è allineata al tuo VPS (**nginx → Apache `7081` oppure nginx → PHP-FPM**) e usa una **`limit_req_zone` globale** nel contesto `http {}` + snippet **per dominio** nel file incluso da Plesk (`vhost_nginx.conf`).

**Stato sul VPS `vps-cb9457cf.vps.ovh.net`:** è stato creato `/etc/nginx/conf.d/00-wp-login-rate-limit-zones.conf` con `rate=12r/m` e `limit_req_status 429`, `nginx -t` e `reload` eseguiti. Fino a quando nessun dominio aggiunge un `location` con `limit_req zone=wp_login_per_ip`, il traffico **non** cambia. Il passo mancante è **solo** incollare lo snippet B2 o B3 per ogni dominio (dopo aver scelto FPM vs proxy).

---

## Parte A — Una tantum sul server (zone condivise)

*(Su questo VPS il file delle zone è già stato installato e nginx ricaricato; puoi saltare alla Parte B salvo modificare `rate=`.)*

### A1. File delle zone (contesto `http`)

Path consigliato:

`/etc/nginx/conf.d/00-wp-login-rate-limit-zones.conf`

Contenuto (valori **conservativi**; regolabili):

```nginx
# Zone condivisa: richieste verso wp-login per IP sorgente
# 12 richieste/minuto in media; burst gestibile nel location (sotto)
limit_req_zone $binary_remote_addr zone=wp_login_per_ip:10m rate=12r/m;

# Risposta HTTP per superamento limite (senza HTML generico di errore sito)
limit_req_status 429;
```

Comandi:

```bash
nginx -t && systemctl reload nginx
```

**Nota:** finché nessun `location` usa `limit_req zone=wp_login_per_ip`, il file non cambia il comportamento del traffico.

---

## Parte B — Per dominio (dove incollare lo snippet)

In **Plesk → Domini → `<dominio>` → Apache e nginx → Direttive nginx aggiuntive per questo dominio**

Plesk salva nel file:

`/var/www/vhosts/system/<dominio>/conf/vhost_nginx.conf`

(incluso **alla fine** del `server { ... }` HTTPS).

### B1. Quale modello usare?

Apri (solo lettura) il config generato:

`/var/www/vhosts/system/<dominio>/conf/nginx.conf`

- Se esiste **`location ~ \.php`** con **`fastcgi_pass unix:/var/www/vhosts/system/<dominio>/php-fpm.sock`** → usa **Snippet FPM**.
- Se **non** c’è il blocco PHP-FPM e tutto passa da **`location /`** con **`proxy_pass ...7081`** → usa **Snippet proxy**.

*(Su questo server alcuni siti sono FPM, altri solo proxy + cache: non sono intercambiabili.)*

---

### B2. Snippet — variante **PHP-FPM** (nginx termina su PHP-FPM)

Sostituisci `<DOMINIO>` con il nome del dominio Plesk (es. `gustodiromagna.com`).

```nginx
# Rate limit login WordPress (PHP-FPM)
# ^~ ha priorità sul regex generico `location ~ \.php` di Plesk
location ^~ /wp-login.php {
	limit_req zone=wp_login_per_ip burst=8 nodelay;
	limit_req_status 429;

	fastcgi_read_timeout 128;
	fastcgi_split_path_info ^((?U).+\.php)(/?.+)$;
	try_files $uri $fastcgi_script_name =404;
	fastcgi_param PATH_INFO $fastcgi_path_info;
	fastcgi_pass "unix:/var/www/vhosts/system/<DOMINIO>/php-fpm.sock";
	include /etc/nginx/fastcgi.conf;
}
```

---

### B3. Snippet — variante **proxy verso Apache** (`7081`)

Per domini **senza** blocco `fastcgi` per `.php` (tutto via `location /`), copia **gli stessi** `proxy_*` del tuo `location /` se hai **proxy_cache** o timeout custom — altrimenti questa versione “minima” è un buon punto di partenza:

```nginx
# Rate limit login WordPress (proxy → Apache backend Plesk)
location ^~ /wp-login.php {
	limit_req zone=wp_login_per_ip burst=8 nodelay;
	limit_req_status 429;

	proxy_read_timeout 128;
	proxy_pass "https://127.0.0.1:7081";
	proxy_hide_header upgrade;
	proxy_ssl_server_name on;
	proxy_ssl_name $host;
	proxy_ssl_session_reuse off;
	proxy_set_header Host             $host;
	proxy_set_header X-Real-IP        $remote_addr;
	proxy_set_header X-Forwarded-For  $proxy_add_x_forwarded_for;
	proxy_set_header X-Accel-Internal /internal-nginx-static-location;
	access_log off;
}
```

Se il tuo `location /` include **`proxy_cache`** e direttive collegate, **replicale** qui per coerenza (stesso upstream e stessa logica di cache/bypass), altrimenti il solo blocco minimo può comportarsi diversamente dal `location /` per quel dominio. Verifica sempre con `nginx -t`.

---

## Parte C — Piano hosting / più domini

- **Domini singoli:** ripeti lo snippet B2 o B3 dopo aver classificato ogni dominio (FPM vs proxy).
- **Template di dominio / piano:** in Plesk puoi applicare le stesse “Direttive nginx aggiuntive” a un **template di dominio** o propagare la policy ai nuovi siti — la procedura esatta dipende da come avete strutturato **Service plans** e template (stesso concetto: `vhost_nginx.conf` per ogni virtual host).

Non esiste un unico “snippet universale” se avete **sia FPM sia solo-proxy**: il modello va scelto **per dominio**.

---

## Parte D — Checklist di test **prima** di rimuovere *Limit Login Attempts Reloaded* (LLAR)

### D1. Pre-check

1. **Backup** del file `vhost_nginx.conf` e/o esportazione impostazioni dominio da Plesk.
2. Conferma che **Fail2Ban** `plesk-wordpress` sia attivo:  
   `fail2ban-client status plesk-wordpress`
3. Annota **IP di casa / ufficio** per test (evitare di restare chiusi fuori senza console).

### D2. Dopo aver applicato zone + snippet dominio

1. `nginx -t` → deve essere **OK**.
2. `systemctl reload nginx` (o da Plesk “Riapplica configurazioni”).
3. **Login corretto:** username/password validi → deve funzionare senza 429.
4. **Login errato:** 3–5 tentativi sbagliati → ancora accessibile (non devi bloccarti subito); verifica che **non** compaiano errori strani nel browser.
5. **Stress legittimo del limite:** da **un altro IP** (es. mobile in 4G), script o loop controllato: superato il rate, ti aspetti **HTTP 429** su `POST /wp-login.php` (o risposta minimal testo).
6. **Log:** controlla `proxy_access_ssl_log` / `access_ssl_log` del dominio e `error_log` nginx per il dominio — niente loop di 502/504.
7. **Fail2Ban:** dopo tentativi falliti, nei log Apache deve comparire ancora la riga utile al filtro (`POST ... wp-login.php ... 200`). Verifica che il jail incrementi i “failed” come prima.

### D3. Periodo di osservazione

1. **24–48 ore** di monitoraggio: nessun ticket “non riesco ad accedere” da utenti reali.
2. Controlla **falsi positivi** (uffici con IP unico con molti utenti): se necessario abbassa `rate=` o alza `burst=` leggermente.

### D4. Rimozione LLAR

1. Su **un solo sito pilota**, disattiva LLAR (non tutti subito).
2. Ripeti test login e monitoraggio **1–2 giorni**.
3. Estendi agli altri siti **a blocchi**.

### D5. Rollback rapido

1. Rimuovi il blocco `location ^~ /wp-login.php { ... }` dalle direttive nginx del dominio.
2. `nginx -t && systemctl reload nginx`.
3. Riattiva LLAR sul dominio se serve tornare allo stato precedente.

---

## Rapporto con LLAR e Fail2Ban

- **Nginx `limit_req`:** “ammorbidisce” picchi e riduce carico **prima** di PHP/Apache.
- **Fail2Ban:** ban IP su pattern di login falliti (già presente sul server).
- **LLAR:** lockout applicativo + email; ridondante per molti casi se nginx + Fail2Ban sono verificati.

Rimuovere LLAR è sensato **dopo** i test sopra, non prima.
