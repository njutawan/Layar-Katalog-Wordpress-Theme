#!/usr/bin/env bash
#
# Quarterly backup-restore rehearsal for Layar Katalog.
#
# docs/BACKUP-RESTORE.md specifies a drill every quarter. This script performs
# the mechanical part of it: dump the live database, restore it into a scratch
# database, verify that everything the theme and plugin own actually survived,
# and print the log row for the runbook. It never writes to the live database.
#
#   bash tests/wp/rehearse-restore.sh --target-db=lkc_drill_2026q4 \
#                                     --yes-drop=lkc_drill_2026q4
#
#   --path=DIR                  WordPress root, if not the current directory
#   --target-db=NAME            scratch database to restore into (required)
#   --yes-drop=NAME             must repeat the target name; it is dropped first
#   --verify-only               verify the live database, skip dump and restore
#   --keep                      leave the scratch database in place afterwards
#   --keep-dump                 leave the .sql dump in place afterwards
#   --dump-file=FILE            use an existing dump instead of taking a new one
#   --restored-wp-config=FILE   compare salts against a backed-up wp-config.php
#
# Exit status is 0 when every check passed or was skipped, 1 when any failed.
#
# The scratch database is dropped and recreated, so the name matters: it must
# not be the live database, and the --yes-drop value must match it exactly.

set -uo pipefail

WP_PATH=''
TARGET_DB=''
YES_DROP=''
VERIFY_ONLY=0
KEEP_DB=0
KEEP_DUMP=0
DUMP_FILE=''
RESTORED_CONFIG=''

PASS=0
FAIL=0
SKIP=0
FAILURES=()

usage() {
	awk 'NR <= 2 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "$0"
}

while [ $# -gt 0 ]; do
	case "$1" in
		--path) WP_PATH="${2:-}"; shift 2 ;;
		--path=*) WP_PATH="${1#--path=}"; shift ;;
		--target-db) TARGET_DB="${2:-}"; shift 2 ;;
		--target-db=*) TARGET_DB="${1#--target-db=}"; shift ;;
		--yes-drop) YES_DROP="${2:-}"; shift 2 ;;
		--yes-drop=*) YES_DROP="${1#--yes-drop=}"; shift ;;
		--dump-file) DUMP_FILE="${2:-}"; shift 2 ;;
		--dump-file=*) DUMP_FILE="${1#--dump-file=}"; shift ;;
		--restored-wp-config) RESTORED_CONFIG="${2:-}"; shift 2 ;;
		--restored-wp-config=*) RESTORED_CONFIG="${1#--restored-wp-config=}"; shift ;;
		--verify-only) VERIFY_ONLY=1; shift ;;
		--keep) KEEP_DB=1; shift ;;
		--keep-dump) KEEP_DUMP=1; shift ;;
		-h|--help) usage; exit 0 ;;
		*) printf 'unknown argument: %s\n\n' "$1" >&2; usage >&2; exit 2 ;;
	esac
done

ok() { PASS=$((PASS + 1)); printf 'PASS  %s\n' "$1"; }
bad() {
	FAIL=$((FAIL + 1))
	FAILURES+=("$1")
	printf 'FAIL  %s\n' "$1"
	if [ -n "${2:-}" ]; then printf '        %s\n' "$2"; fi
}
skip() { SKIP=$((SKIP + 1)); printf 'SKIP  %s — %s\n' "$1" "$2"; }
section() { printf '\n--- %s ---\n' "$1"; }
die() { printf '\nABORT  %s\n' "$1" >&2; exit 2; }

expect() {
	if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "expected [$2], got [$3]"; fi
}
expect_nonzero() {
	if [ -n "$2" ] && [ "$2" != '0' ]; then ok "$1 (${2})"; else bad "$1" "expected a non-zero value, got [$2]"; fi
}

WP_ARGS=()
if [ -n "$WP_PATH" ]; then WP_ARGS+=(--path="$WP_PATH"); fi
run_wp() { command wp "$@" ${WP_ARGS[@]+"${WP_ARGS[@]}"}; }

# Hash the salt definition lines of a wp-config.php without parsing their values:
# salts contain quotes, backslashes and spaces, so comparing the lines verbatim
# is both simpler and stricter than trying to extract each value.
hash_salt_lines() {
	local pattern="^[[:space:]]*define\\([[:space:]]*'(AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT)'"
	if command -v sha256sum >/dev/null 2>&1; then
		grep -E "$pattern" "$1" 2>/dev/null | sort | sha256sum | cut -d' ' -f1
	elif command -v shasum >/dev/null 2>&1; then
		grep -E "$pattern" "$1" 2>/dev/null | sort | shasum -a 256 | cut -d' ' -f1
	else
		grep -E "$pattern" "$1" 2>/dev/null | sort | cksum | cut -d' ' -f1-2
	fi
}

# ------------------------------------------------------------- preflight ----

section 'preflight'

command -v wp >/dev/null 2>&1 || die 'WP-CLI is not installed (https://wp-cli.org)'
SOURCE_DB="$(run_wp config get DB_NAME 2>/dev/null)"
[ -n "$SOURCE_DB" ] || die 'could not read DB_NAME; is this a WordPress root?'
ok "live database is ${SOURCE_DB}"

DB_USER="$(run_wp config get DB_USER 2>/dev/null)"
DB_PASSWORD="$(run_wp config get DB_PASSWORD 2>/dev/null)"
DB_HOST="$(run_wp config get DB_HOST 2>/dev/null)"
TABLE_PREFIX="$(run_wp eval 'global $wpdb; echo $wpdb->prefix;' 2>/dev/null)"
ok "table prefix is ${TABLE_PREFIX}"

if [ "$VERIFY_ONLY" -eq 1 ]; then
	skip 'scratch database checks' '--verify-only was given'
else
	command -v mysql >/dev/null 2>&1 || die 'the mysql client is not installed; it is needed to restore into the scratch database'
	# wp db export shells out to mysqldump, so its absence is fatal too.
	command -v mysqldump >/dev/null 2>&1 || die 'mysqldump is not installed; wp db export needs it'

	[ -n "$TARGET_DB" ] || die '--target-db is required (the scratch database to restore into)'
	case "$TARGET_DB" in
		*[!A-Za-z0-9_\$-]*|'') die "--target-db must only contain letters, digits, underscore, dollar or dash (got: ${TARGET_DB})" ;;
	esac
	if [ "$TARGET_DB" = "$SOURCE_DB" ]; then
		die "refusing to run: --target-db is the live database (${SOURCE_DB})"
	fi
	if [ "$YES_DROP" != "$TARGET_DB" ]; then
		die "the scratch database is dropped and recreated; repeat its name with --yes-drop=${TARGET_DB}"
	fi
	ok "scratch database will be ${TARGET_DB} (live database is untouched)"

	case "$TARGET_DB" in
		*drill*|*scratch*|*tmp*|*temp*|*test*) ;;
		*) printf 'NOTE  %s does not look like a scratch name; double-check before continuing\n' "$TARGET_DB" ;;
	esac
fi

# ------------------------------------------------------------ connection ----

CNF=''
cleanup() {
	if [ -n "$CNF" ] && [ -f "$CNF" ]; then
		rm -f "$CNF"
	fi
}
trap cleanup EXIT

if [ "$VERIFY_ONLY" -eq 0 ]; then
	# Credentials go through a 0600 defaults file instead of the command line, so
	# the password never appears in the process list.
	CNF="$(mktemp "${TMPDIR:-/tmp}/lkc-drill-cnf.XXXXXX")" || die 'could not create a temporary defaults file'
	chmod 600 "$CNF" || die 'could not protect the temporary defaults file'

	MYSQL_HOST="$DB_HOST"
	MYSQL_PORT=''
	MYSQL_SOCKET=''
	case "$DB_HOST" in
		*:*/*)
			MYSQL_SOCKET="${DB_HOST#*:}"
			MYSQL_HOST="${DB_HOST%%:*}"
			;;
		*:*[^/]*)
			MYSQL_PORT="${DB_HOST##*:}"
			MYSQL_HOST="${DB_HOST%%:*}"
			;;
	esac

	{
		printf '[client]\n'
		printf 'user=%s\n' "$DB_USER"
		printf 'password="%s"\n' "$DB_PASSWORD"
		if [ -n "$MYSQL_SOCKET" ]; then printf 'socket=%s\n' "$MYSQL_SOCKET"; fi
		if [ -n "$MYSQL_HOST" ] && [ "$MYSQL_HOST" != 'localhost' ]; then printf 'host=%s\n' "$MYSQL_HOST"; fi
		if [ -n "$MYSQL_PORT" ]; then printf 'port=%s\n' "$MYSQL_PORT"; fi
	} >"$CNF"

	drill_query() { mysql --defaults-extra-file="$CNF" --batch --skip-column-names "$TARGET_DB" -e "$1" 2>/dev/null; }
	if ! mysql --defaults-extra-file="$CNF" --batch --skip-column-names -e 'SELECT 1' >/dev/null 2>&1; then
		die 'could not connect to MySQL with the credentials from wp-config.php'
	fi
	ok 'MySQL credentials from wp-config.php work'
fi

# ------------------------------------------------------------------ dump ----

if [ "$VERIFY_ONLY" -eq 0 ]; then
	section 'dump and restore'

	if [ -n "$DUMP_FILE" ]; then
		[ -f "$DUMP_FILE" ] || die "--dump-file does not exist: ${DUMP_FILE}"
		ok "using the existing dump ${DUMP_FILE}"
	else
		DUMP_FILE="${TMPDIR:-/tmp}/lkc-restore-drill-$(date -u +%Y%m%dT%H%M%SZ).sql"
		if ! run_wp db export "$DUMP_FILE" --add-drop-table >/dev/null 2>&1; then
			die "wp db export failed to write ${DUMP_FILE}"
		fi
		ok "dumped the live database to ${DUMP_FILE}"
	fi

	DUMP_BYTES="$(wc -c <"$DUMP_FILE" 2>/dev/null | tr -d ' ')"
	if [ -z "$DUMP_BYTES" ] || [ "$DUMP_BYTES" -lt 1024 ]; then
		bad 'the dump is not empty' "only ${DUMP_BYTES:-0} bytes; a real catalog dump is far larger"
	else
		ok "the dump is ${DUMP_BYTES} bytes"
	fi

	SOURCE_CHARSET="$(run_wp db query "SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '${SOURCE_DB}'" 2>/dev/null | tail -n 1)"
	SOURCE_COLLATION="$(run_wp db query "SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '${SOURCE_DB}'" 2>/dev/null | tail -n 1)"
	[ -n "$SOURCE_CHARSET" ] || SOURCE_CHARSET='utf8mb4'
	[ -n "$SOURCE_COLLATION" ] || SOURCE_COLLATION='utf8mb4_unicode_520_ci'

	mysql --defaults-extra-file="$CNF" -e "DROP DATABASE IF EXISTS \`${TARGET_DB}\`; CREATE DATABASE \`${TARGET_DB}\` CHARACTER SET ${SOURCE_CHARSET} COLLATE ${SOURCE_COLLATION};" 2>/dev/null \
		|| die "could not recreate the scratch database ${TARGET_DB}"
	ok "recreated ${TARGET_DB} with ${SOURCE_CHARSET}/${SOURCE_COLLATION}"

	STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	if ! mysql --defaults-extra-file="$CNF" "$TARGET_DB" <"$DUMP_FILE" 2>/dev/null; then
		die "restoring ${DUMP_FILE} into ${TARGET_DB} failed"
	fi
	FINISHED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	ok "restored into ${TARGET_DB} (${STARTED} → ${FINISHED})"
fi

# -------------------------------------------------------------- verify -----

section 'the restored copy holds what the plugin and theme own'

# In --verify-only mode the "restored" numbers come from the live database, so
# the same assertions still tell you whether the inventory is complete.
count_restored() {
	if [ "$VERIFY_ONLY" -eq 1 ]; then
		run_wp db query "$1" 2>/dev/null | tail -n 1 | tr -d ' \t'
	else
		drill_query "$1" | tail -n 1 | tr -d ' \t'
	fi
}

RESTORED_TABLES="$(count_restored "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '${TARGET_DB:-$SOURCE_DB}' AND TABLE_NAME LIKE '${TABLE_PREFIX}lkc\\_%'")"
expect 'all four plugin tables are present' '4' "$RESTORED_TABLES"

for table in user_ratings push_subscriptions audit_log csp_reports; do
	ROWS="$(count_restored "SELECT COUNT(*) FROM \`${TABLE_PREFIX}lkc_${table}\`")"
	printf '      %-22s %s row(s)\n' "${TABLE_PREFIX}lkc_${table}" "${ROWS:-?}"
	if [ -z "$ROWS" ]; then
		bad "table ${TABLE_PREFIX}lkc_${table} can be read" 'query returned nothing'
	else
		ok "table ${TABLE_PREFIX}lkc_${table} can be read"
	fi
done

TITLES="$(count_restored "SELECT COUNT(*) FROM \`${TABLE_PREFIX}posts\` WHERE post_type = 'lk_title'")"
EPISODES="$(count_restored "SELECT COUNT(*) FROM \`${TABLE_PREFIX}posts\` WHERE post_type = 'lk_episode'")"
expect_nonzero 'catalog titles survived' "$TITLES"
expect_nonzero 'episodes survived' "$EPISODES"

VIDEO_META="$(count_restored "SELECT COUNT(*) FROM \`${TABLE_PREFIX}postmeta\` WHERE meta_key LIKE '\\_lk\\_video\\_url%'")"
expect_nonzero 'episode video URLs survived' "$VIDEO_META"

PUSH_OPTION="$(count_restored "SELECT COUNT(*) FROM \`${TABLE_PREFIX}options\` WHERE option_name = 'lkc_push_options'")"
expect 'the push settings option survived' '1' "$PUSH_OPTION"

ADS_OPTION="$(count_restored "SELECT COUNT(*) FROM \`${TABLE_PREFIX}options\` WHERE option_name = 'layarkatalog_ads'")"
expect 'the advertising-code option survived' '1' "$ADS_OPTION"

THEME_MODS="$(count_restored "SELECT COUNT(*) FROM \`${TABLE_PREFIX}options\` WHERE option_name = 'theme_mods_${THEME_SLUG:-layar-katalog}'")"
if [ "$THEME_MODS" = '1' ]; then
	ok 'the theme mods survived'
else
	ACTUAL_STYLESHEET="$(run_wp eval 'echo get_stylesheet();' 2>/dev/null)"
	THEME_MODS="$(count_restored "SELECT COUNT(*) FROM \`${TABLE_PREFIX}options\` WHERE option_name = 'theme_mods_${ACTUAL_STYLESHEET}'")"
	expect "the theme mods survived (theme_mods_${ACTUAL_STYLESHEET})" '1' "$THEME_MODS"
fi

CRON_OPTION="$(count_restored "SELECT COUNT(*) FROM \`${TABLE_PREFIX}options\` WHERE option_name = 'cron' AND option_value LIKE '%lkc_prune_stale_push_subscriptions%'")"
expect 'the pruning cron survived inside the cron option' '1' "$CRON_OPTION"

# --------------------------------------------------- the salt dependency ----

section 'the VAPID key can still be used after a restore'

if [ "$VERIFY_ONLY" -eq 1 ]; then
	skip 'restored ciphertext matches the live ciphertext' '--verify-only was given'
else
	LIVE_PRIVATE="$(run_wp eval '$o = get_option( "lkc_push_options", array() ); echo is_array( $o ) && isset( $o["private_key"] ) ? (string) $o["private_key"] : "";' 2>/dev/null)"
	RESTORED_PRIVATE="$(drill_query "SELECT option_value FROM \`${TABLE_PREFIX}options\` WHERE option_name = 'lkc_push_options'" 2>/dev/null | grep -o 'lkc1:[^"]*' | head -n 1)"

	if [ -z "$LIVE_PRIVATE" ]; then
		skip 'restored ciphertext matches the live ciphertext' 'no VAPID key is configured on the live site yet'
	elif [ -z "$RESTORED_PRIVATE" ]; then
		bad 'the encrypted VAPID key survived the restore' 'no lkc1: envelope found in the restored option'
	else
		case "$LIVE_PRIVATE" in
			*"$RESTORED_PRIVATE"*) ok 'the encrypted VAPID key survived the restore byte for byte' ;;
			*) bad 'the encrypted VAPID key survived the restore byte for byte' 'the restored envelope differs from the live one' ;;
		esac
	fi
fi

DECRYPTABLE="$(run_wp eval 'echo function_exists( "lkc_get_push_settings" ) ? ( "" !== lkc_get_push_settings()["private_key"] ? "yes" : "no" ) : "unknown";' 2>/dev/null)"
case "$DECRYPTABLE" in
	yes) ok 'the live salts decrypt the stored key' ;;
	no) bad 'the live salts decrypt the stored key' 'salts changed since the key was saved; regenerate it in Pengaturan → Notifikasi Episode' ;;
	*) skip 'the live salts decrypt the stored key' "state [$DECRYPTABLE]; is the plugin active?" ;;
esac

if [ -n "$RESTORED_CONFIG" ]; then
	if [ ! -f "$RESTORED_CONFIG" ]; then
		bad 'the backed-up wp-config.php was readable' "${RESTORED_CONFIG} does not exist"
	else
		LIVE_CONFIG="$(run_wp config path 2>/dev/null)"
		SALT_PATTERN="^[[:space:]]*define\\([[:space:]]*'(AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT)'"
		LIVE_COUNT="$(grep -cE "$SALT_PATTERN" "$LIVE_CONFIG" 2>/dev/null || printf '0')"

		if [ "$LIVE_COUNT" != '8' ]; then
			skip 'the backed-up wp-config.php carries the same salts' \
				"the live ${LIVE_CONFIG} defines ${LIVE_COUNT} of the 8 salts inline, so a text comparison would be meaningless"
		else
			LIVE_HASH="$(hash_salt_lines "$LIVE_CONFIG")"
			RESTORED_HASH="$(hash_salt_lines "$RESTORED_CONFIG")"
			RESTORED_COUNT="$(grep -cE "$SALT_PATTERN" "$RESTORED_CONFIG" 2>/dev/null || printf '0')"

			if [ "$RESTORED_COUNT" != '8' ]; then
				bad 'the backed-up wp-config.php defines all 8 salts' "found ${RESTORED_COUNT} of 8; the backup is incomplete or the salts were moved elsewhere"
			elif [ -n "$LIVE_HASH" ] && [ "$LIVE_HASH" = "$RESTORED_HASH" ]; then
				ok "all 8 salt definitions in the backup match the live ${LIVE_CONFIG}"
			else
				bad 'all 8 salt definitions in the backup match the live wp-config.php' \
					'they differ; restoring this backup would make the stored VAPID key unreadable and every push subscription would have to opt in again'
			fi
		fi
	fi
else
	skip 'the backed-up wp-config.php carries the same salts' 'pass --restored-wp-config=FILE to test this; without the salts the encrypted VAPID key cannot be recovered'
fi

# ------------------------------------------------------------- cleanup -----

if [ "$VERIFY_ONLY" -eq 0 ]; then
	section 'cleanup'
	if [ "$KEEP_DB" -eq 1 ]; then
		printf '      keeping %s (drop it with: mysql -e "DROP DATABASE \`%s\`")\n' "$TARGET_DB" "$TARGET_DB"
	else
		mysql --defaults-extra-file="$CNF" -e "DROP DATABASE IF EXISTS \`${TARGET_DB}\`;" 2>/dev/null \
			&& ok "dropped the scratch database ${TARGET_DB}" \
			|| bad "dropped the scratch database ${TARGET_DB}" 'drop it manually'
	fi
	if [ "$KEEP_DUMP" -eq 1 ]; then
		printf '      keeping the dump at %s (it contains the whole database; store it encrypted)\n' "$DUMP_FILE"
	else
		rm -f "$DUMP_FILE" && ok 'removed the dump file'
	fi
fi

# --------------------------------------------------------------- summary ----

printf '\n=================================\n'
printf '%d passed, %d failed, %d skipped\n' "$PASS" "$FAIL" "$SKIP"

printf '\nLog row for docs/BACKUP-RESTORE.md:\n\n'
printf '| %s | %s | %s | %s/%s | %s |\n' \
	"$(date -u +%Y-%m-%d)" \
	"${TARGET_DB:-live (verify only)}" \
	"${DUMP_BYTES:-0} bytes" \
	"$PASS" "$((PASS + FAIL))" \
	"$([ "$FAIL" -eq 0 ] && printf 'OK' || printf 'FAILED')"

if [ "$FAIL" -gt 0 ]; then
	printf '\nFailed checks:\n'
	for item in ${FAILURES[@]+"${FAILURES[@]}"}; do
		printf '  - %s\n' "$item"
	done
	exit 1
fi

printf '\nStill manual: load the restored copy in a browser and click through a title,\nan episode and a search, then confirm a test notification actually arrives.\n'
exit 0
