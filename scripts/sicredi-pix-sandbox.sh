#!/usr/bin/env bash
#
# Validação interativa do Pix Sicredi (API Pix BACEN) — homologação/sandbox.
# Espelha o que o package laravel-bancos envia (endpoints, headers, payload,
# OAuth2 + mTLS), então validar aqui = validar o de-para do package.
#
# IMPORTANTE: o Pix do Sicredi EXIGE certificado mTLS (.cer PEM + .key sem senha).
# Sem o certificado, toda chamada retorna 403.
#
# Uso:
#   ./scripts/sicredi-pix-sandbox.sh
#
# Credenciais: carrega ./scripts/sicredi-pix-sandbox.env se existir (gitignored)
# ou pergunta interativamente.

set -uo pipefail

if [[ -t 1 ]]; then
  C_OK=$'\e[32m'; C_ERR=$'\e[31m'; C_INFO=$'\e[36m'; C_DIM=$'\e[2m'; C_RST=$'\e[0m'
else
  C_OK=''; C_ERR=''; C_INFO=''; C_DIM=''; C_RST=''
fi
info() { echo "${C_INFO}$*${C_RST}"; }
ok()   { echo "${C_OK}$*${C_RST}"; }
err()  { echo "${C_ERR}$*${C_RST}" >&2; }

for dep in curl jq; do
  command -v "$dep" >/dev/null 2>&1 || { err "Faltando: $dep (sudo apt install $dep)"; exit 1; }
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$SCRIPT_DIR/sicredi-pix-sandbox.env"
if [[ -f "$ENV_FILE" ]]; then info "Carregando credenciais de $ENV_FILE"; source "$ENV_FILE"; fi

ask() { # ask VAR "label" [secret]
  local var="$1" label="$2" secret="${3:-}" cur="${!1:-}"
  [[ -n "$cur" ]] && return 0
  if [[ "$secret" == "secret" ]]; then read -rsp "$label: " "$var"; echo
  else read -rp "$label: " "$var"; fi
}

# Produção: https://api-pix.sicredi.com.br | Homologação: solicite a URL ao Sicredi
SICREDI_BASE="${SICREDI_BASE:-https://api-pix.sicredi.com.br}"
SCOPES="${SCOPES:-cob.read cob.write cobv.read cobv.write pix.read pix.write webhook.read webhook.write}"
ask CLIENT_ID     "client_id"
ask CLIENT_SECRET "client_secret" secret
ask CHAVE_PIX     "chave Pix do recebedor"
ask CERT          "caminho do certificado .cer (PEM)"
ask KEY           "caminho da chave .key (sem senha)"

[[ -r "$CERT" ]] || { err "Certificado não encontrado/legível: $CERT"; exit 1; }
[[ -r "$KEY"  ]] || { err "Chave privada não encontrada/legível: $KEY"; exit 1; }

info "Base: $SICREDI_BASE | chave: $CHAVE_PIX | mTLS: $(basename "$CERT")"

TOKEN=""
TXID=""
RESP="/tmp/sicredi_pix_resp.$$"
trap 'rm -f "$RESP"' EXIT

# txid BACEN: 26–35 alfanuméricos
gerar_txid() { echo "tst$(date +%s)$(tr -dc 'a-z0-9' </dev/urandom | head -c 12)" | cut -c1-35; }

autenticar() {
  info "→ POST /oauth/token (client_credentials, Basic auth, mTLS)"
  local status
  status=$(curl --cert "$CERT" --key "$KEY" -sS -o "$RESP" -w '%{http_code}' \
    -X POST "$SICREDI_BASE/oauth/token" \
    -u "$CLIENT_ID:$CLIENT_SECRET" \
    -H "Content-Type: application/x-www-form-urlencoded" \
    --data-urlencode "grant_type=client_credentials" \
    --data-urlencode "scope=$SCOPES")
  if [[ "$status" == "200" ]]; then
    TOKEN=$(jq -r '.access_token // empty' "$RESP")
    [[ -n "$TOKEN" ]] && { ok "✓ Autenticado (token ${TOKEN:0:18}..., expira em $(jq -r '.expires_in // "?"' "$RESP")s)"; return 0; }
  fi
  err "✗ Falha na autenticação (HTTP $status):"; jq . "$RESP" 2>/dev/null || cat "$RESP"
  [[ "$status" == "403" ]] && err "  403 normalmente é problema de mTLS (certificado ausente/inválido)."
  return 1
}

garantir_token() { [[ -n "$TOKEN" ]] || autenticar; }

# api METHOD PATH [JSON] — autenticada com mTLS + Bearer, retry único em 401
api() {
  local method="$1" path="$2" data="${3:-}"
  garantir_token || return 1
  local status; status=$(_api_call "$method" "$path" "$data")
  if [[ "$status" == "401" ]]; then info "(401 — reautenticando)"; autenticar && status=$(_api_call "$method" "$path" "$data"); fi
  LAST_STATUS="$status"
  if [[ "$status" =~ ^2 ]]; then ok "HTTP $status"; else err "HTTP $status"; fi
  jq . "$RESP" 2>/dev/null || cat "$RESP"
}

_api_call() {
  local method="$1" path="$2" data="${3:-}"
  local args=(--cert "$CERT" --key "$KEY" -sS -o "$RESP" -w '%{http_code}' -X "$method" "$SICREDI_BASE$path"
    -H "Authorization: Bearer $TOKEN" -H "Accept: application/json")
  [[ -n "$data" ]] && args+=(-H "Content-Type: application/json" -d "$data")
  curl "${args[@]}"
}

cob_criar() {
  local valor exp
  read -rp "Valor (R\$) [1.00]: " valor; valor="${valor:-1.00}"
  read -rp "Expiração (s) [3600]: " exp; exp="${exp:-3600}"
  TXID=$(gerar_txid)
  local payload
  payload=$(jq -n --arg v "$valor" --argjson e "$exp" --arg ch "$CHAVE_PIX" \
    '{calendario:{expiracao:$e}, valor:{original:$v}, chave:$ch, solicitacaoPagador:"Teste laravel-bancos"}')
  info "→ PUT /api/v3/cob/$TXID  (cobrança imediata com txid)"
  api PUT "/api/v3/cob/$TXID" "$payload"
  [[ "${LAST_STATUS:-}" =~ ^2 ]] && ok "→ txid=$TXID  pixCopiaECola=$(jq -r '.pixCopiaECola // "—"' "$RESP")"
}

cob_consultar() {
  local t="${1:-$TXID}"; [[ -z "$t" ]] && read -rp "txid: " t
  info "→ GET /api/v3/cob/$t"; api GET "/api/v3/cob/$t"
}

cob_cancelar() {
  local t="${1:-$TXID}"; [[ -z "$t" ]] && read -rp "txid: " t
  info "→ PATCH /api/v3/cob/$t (REMOVIDA_PELO_USUARIO_RECEBEDOR)"
  api PATCH "/api/v3/cob/$t" '{"status":"REMOVIDA_PELO_USUARIO_RECEBEDOR"}'
}

cobv_criar() {
  local valor venc
  read -rp "Valor (R\$) [1.00]: " valor; valor="${valor:-1.00}"
  read -rp "Vencimento YYYY-MM-DD [$(date -d '+30 days' +%Y-%m-%d)]: " venc
  venc="${venc:-$(date -d '+30 days' +%Y-%m-%d)}"
  TXID=$(gerar_txid)
  local payload
  payload=$(jq -n --arg v "$valor" --arg venc "$venc" --arg ch "$CHAVE_PIX" \
    '{calendario:{dataDeVencimento:$venc, validadeAposVencimento:0}, valor:{original:$v}, chave:$ch}')
  info "→ PUT /api/v2/cobv/$TXID  (cobrança com vencimento)"
  api PUT "/api/v2/cobv/$TXID" "$payload"
  [[ "${LAST_STATUS:-}" =~ ^2 ]] && ok "→ txid=$TXID"
}

pix_consultar() {
  local e2e; read -rp "endToEndId (e2eid): " e2e
  info "→ GET /api/v2/pix/$e2e"; api GET "/api/v2/pix/$e2e"
}

webhook_config() {
  local url; read -rp "URL do webhook (https): " url
  info "→ PUT /api/v2/webhook/$CHAVE_PIX"
  api PUT "/api/v2/webhook/$CHAVE_PIX" "$(jq -n --arg u "$url" '{webhookUrl:$u}')"
}
webhook_consultar() { info "→ GET /api/v2/webhook/$CHAVE_PIX"; api GET "/api/v2/webhook/$CHAVE_PIX"; }
webhook_remover()   { info "→ DELETE /api/v2/webhook/$CHAVE_PIX"; api DELETE "/api/v2/webhook/$CHAVE_PIX"; }

menu() {
  cat <<MENU

${C_INFO}=== Sicredi Pix — Homologação (mTLS) ===${C_RST}
  1) Autenticar (token)
  2) Cobrança imediata (cob) — criar
  3) Consultar cobrança imediata
  4) Cancelar cobrança imediata
  5) Cobrança com vencimento (cobv) — criar
  6) Consultar Pix recebido (por e2eid)
  7) Webhook: configurar
  8) Webhook: consultar
  9) Webhook: remover
  0) Sair
${C_DIM}  token: $([[ -n "$TOKEN" ]] && echo presente || echo ausente) | último txid: ${TXID:-—}${C_RST}
MENU
  read -rp "opção> " opt
  case "$opt" in
    1) autenticar ;;
    2) cob_criar ;;
    3) cob_consultar ;;
    4) cob_cancelar ;;
    5) cobv_criar ;;
    6) pix_consultar ;;
    7) webhook_config ;;
    8) webhook_consultar ;;
    9) webhook_remover ;;
    0) exit 0 ;;
    *) err "opção inválida" ;;
  esac
}

while true; do menu; done
