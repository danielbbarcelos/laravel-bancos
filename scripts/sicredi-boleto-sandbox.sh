#!/usr/bin/env bash
#
# Validação interativa do boleto Sicredi (API de Cobrança) — SANDBOX.
# Espelha exatamente o que o package laravel-bancos envia (endpoints, headers,
# payload), então validar aqui = validar o de-para do package.
#
# Uso:
#   ./scripts/sicredi-boleto-sandbox.sh
#
# Credenciais: o script carrega ./scripts/sicredi-sandbox.env se existir
# (copie de sicredi-sandbox.env.example e preencha — esse arquivo é gitignored),
# ou pergunta interativamente. Nada é enviado para fora do sandbox.

set -uo pipefail

# ----------------------------------------------------------------------------
# Cores e helpers de saída
# ----------------------------------------------------------------------------
if [[ -t 1 ]]; then
  C_OK=$'\e[32m'; C_ERR=$'\e[31m'; C_INFO=$'\e[36m'; C_DIM=$'\e[2m'; C_RST=$'\e[0m'
else
  C_OK=''; C_ERR=''; C_INFO=''; C_DIM=''; C_RST=''
fi
info() { echo "${C_INFO}$*${C_RST}"; }
ok()   { echo "${C_OK}$*${C_RST}"; }
err()  { echo "${C_ERR}$*${C_RST}" >&2; }

# ----------------------------------------------------------------------------
# Dependências
# ----------------------------------------------------------------------------
for dep in curl jq; do
  command -v "$dep" >/dev/null 2>&1 || { err "Faltando: $dep (instale com: sudo apt install $dep)"; exit 1; }
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$SCRIPT_DIR/sicredi-sandbox.env"

# ----------------------------------------------------------------------------
# Credenciais
# ----------------------------------------------------------------------------
if [[ -f "$ENV_FILE" ]]; then
  info "Carregando credenciais de $ENV_FILE"
  # shellcheck disable=SC1090
  source "$ENV_FILE"
fi

ask() { # ask VAR "label" [secret]
  local var="$1" label="$2" secret="${3:-}" cur="${!1:-}"
  [[ -n "$cur" ]] && return 0
  if [[ "$secret" == "secret" ]]; then
    read -rsp "$label: " "$var"; echo
  else
    read -rp "$label: " "$var"
  fi
}

SICREDI_BASE="${SICREDI_BASE:-https://api-parceiro.sicredi.com.br/sb}"
ask API_KEY      "x-api-key"
ask USERNAME     "username (beneficiário+cooperativa, 9 díg)"
ask PASSWORD     "password (código de acesso)" secret
ask COOPERATIVA  "cooperativa (4 díg)"
ask POSTO        "posto (2 díg)"
ask COD_BENEF    "codigoBeneficiario (5 díg)"

info "Base: $SICREDI_BASE  | cooperativa $COOPERATIVA posto $POSTO benef $COD_BENEF"

# ----------------------------------------------------------------------------
# Detecção de ambiente + guard de produção
# ----------------------------------------------------------------------------
# Sandbox = base termina em /sb (com ou sem barra); qualquer outra = PRODUÇÃO.
if [[ "$SICREDI_BASE" =~ /sb/?$ ]]; then
  EH_PRODUCAO=0
else
  EH_PRODUCAO=1
  cat <<BANNER
${C_ERR}
  ############################################################
  #                                                          #
  #   ⚠  ATENÇÃO: AMBIENTE DE  P R O D U Ç Ã O  ⚠            #
  #                                                          #
  #   Operações de gravação criam/cancelam BOLETOS REAIS     #
  #   (cobranças válidas, registradas no CIP). NÃO é teste.  #
  #                                                          #
  ############################################################
${C_RST}
BANNER
fi

# Exige confirmação digitada antes de qualquer operação que grave/baixe boleto
# em produção. Em sandbox, passa direto. Retorna !=0 se o usuário não confirmar.
confirmar_producao() {
  [[ "$EH_PRODUCAO" == "1" ]] || return 0
  local acao="${1:-esta operação}" resp
  err "► ${acao^^} em PRODUÇÃO — isso tem efeito REAL e irreversível."
  read -rp "Para confirmar, digite PRODUCAO (maiúsculas): " resp
  if [[ "$resp" == "PRODUCAO" ]]; then
    return 0
  fi
  err "✗ Cancelado (confirmação não conferida)."
  return 1
}

# ----------------------------------------------------------------------------
# Estado da sessão
# ----------------------------------------------------------------------------
TOKEN=""
NOSSO=""
LINHA=""
RESP="/tmp/sicredi_resp.$$"
LOG="$SCRIPT_DIR/sicredi-boleto-sandbox.log"
trap 'rm -f "$RESP"' EXIT

# Registra toda a sessão (stdout+stderr) também em $LOG, sem atrapalhar os
# prompts interativos — assim, mesmo sem scrollback no terminal, você pode
# revisar os passos depois com: less scripts/sicredi-boleto-sandbox.log
exec > >(tee -a "$LOG") 2>&1
info "Sessão registrada em: $LOG"

# Pausa até Enter (para ler a saída antes de seguir/redesenhar).
pausa() { echo; read -rp "${C_DIM}— Enter para continuar —${C_RST} " _; }

# ----------------------------------------------------------------------------
# Autenticação
# ----------------------------------------------------------------------------
autenticar() {
  info "→ POST /auth/openapi/token (grant_type=password)"
  local status
  status=$(curl -sS -o "$RESP" -w '%{http_code}' -X POST "$SICREDI_BASE/auth/openapi/token" \
    -H "x-api-key: $API_KEY" -H "context: COBRANCA" \
    -H "Content-Type: application/x-www-form-urlencoded" \
    --data-urlencode "grant_type=password" \
    --data-urlencode "username=$USERNAME" \
    --data-urlencode "password=$PASSWORD" \
    --data-urlencode "scope=cobranca")
  if [[ "$status" == "200" ]]; then
    TOKEN=$(jq -r '.access_token // empty' "$RESP")
    if [[ -n "$TOKEN" ]]; then
      ok "✓ Autenticado (token ${TOKEN:0:18}..., expira em $(jq -r '.expires_in // "?"' "$RESP")s)"
      return 0
    fi
  fi
  err "✗ Falha na autenticação (HTTP $status):"; jq . "$RESP" 2>/dev/null || cat "$RESP"
  return 1
}

garantir_token() { [[ -n "$TOKEN" ]] || autenticar; }

# Requisição autenticada JSON com retry único em 401. Uso: api METHOD PATH [JSON]
# Resultado: imprime corpo (jq) e devolve status em $LAST_STATUS.
api() {
  local method="$1" path="$2" data="${3:-}"
  garantir_token || return 1
  local status; status=$(_api_call "$method" "$path" "$data")
  if [[ "$status" == "401" ]]; then
    info "(401 — token expirado, reautenticando e repetindo)"
    autenticar && status=$(_api_call "$method" "$path" "$data")
  fi
  LAST_STATUS="$status"
  if [[ "$status" =~ ^2 ]]; then ok "HTTP $status"; else err "HTTP $status"; fi
  jq . "$RESP" 2>/dev/null || cat "$RESP"
}

_api_call() {
  local method="$1" path="$2" data="${3:-}"
  local args=(-sS -o "$RESP" -w '%{http_code}' -X "$method" "$SICREDI_BASE$path"
    -H "x-api-key: $API_KEY" -H "Authorization: Bearer $TOKEN"
    -H "cooperativa: $COOPERATIVA" -H "posto: $POSTO" -H "codigoBeneficiario: $COD_BENEF"
    -H "Accept: application/json")
  [[ -n "$data" ]] && args+=(-H "Content-Type: application/json" -d "$data")
  curl "${args[@]}"
}

# ----------------------------------------------------------------------------
# Operações de boleto
# ----------------------------------------------------------------------------
cadastrar() {
  confirmar_producao "cadastrar boleto" || return 1
  local valor venc nome doc
  read -rp "Valor (R\$) [1.00]: " valor;      valor="${valor:-1.00}"
  read -rp "Vencimento YYYY-MM-DD [$(date -d '+60 days' +%Y-%m-%d)]: " venc
  venc="${venc:-$(date -d '+60 days' +%Y-%m-%d)}"
  read -rp "Nome do pagador [Fulano de Teste]: " nome; nome="${nome:-Fulano de Teste}"
  read -rp "Documento do pagador (CPF/CNPJ) [11144477735]: " doc; doc="${doc:-11144477735}"

  local ts; ts=$(date +%s); local seu="T${ts: -9}"   # seuNumero: máx. 10 caracteres (regra Sicredi)
  local tipo_pessoa="PESSOA_FISICA"
  [[ "${#doc}" -gt 11 ]] && tipo_pessoa="PESSOA_JURIDICA"

  local payload
  payload=$(jq -n \
    --arg cb "$COD_BENEF" --arg venc "$venc" --argjson valor "$valor" \
    --arg seu "$seu" --arg tp "$tipo_pessoa" --arg doc "$doc" --arg nome "$nome" \
    '{
      tipoCobranca: "NORMAL",
      codigoBeneficiario: $cb,
      especieDocumento: "DUPLICATA_MERCANTIL_INDICACAO",
      dataVencimento: $venc,
      valor: $valor,
      seuNumero: $seu,
      pagador: {
        tipoPessoa: $tp, documento: $doc, nome: $nome,
        endereco: "Rua Teste 100", cidade: "Porto Alegre", uf: "RS", cep: "91250000"
      }
    }')

  info "→ POST /cobranca/boleto/v1/boletos  (seuNumero=$seu)"
  api POST "/cobranca/boleto/v1/boletos" "$payload"
  if [[ "${LAST_STATUS:-}" =~ ^2 ]]; then
    NOSSO=$(jq -r '.nossoNumero // empty' "$RESP")
    LINHA=$(jq -r '.linhaDigitavel // empty' "$RESP")
    ok "→ guardado: nossoNumero=$NOSSO  linhaDigitavel=$LINHA"
  fi
}

consultar() {
  local nn="${1:-$NOSSO}"
  [[ -z "$nn" ]] && read -rp "nossoNumero: " nn
  info "→ GET /cobranca/boleto/v1/boletos?nossoNumero=$nn"
  api GET "/cobranca/boleto/v1/boletos?codigoBeneficiario=$COD_BENEF&nossoNumero=$nn"
}

pdf() {
  [[ -z "$LINHA" ]] && read -rp "linhaDigitavel: " LINHA
  garantir_token || return 1
  local out="boleto-${NOSSO:-teste}.pdf" status
  info "→ GET /cobranca/boleto/v1/boletos/pdf  (Accept: application/pdf)"
  status=$(curl -sS -o "$out" -w '%{http_code}' \
    "$SICREDI_BASE/cobranca/boleto/v1/boletos/pdf?linhaDigitavel=$LINHA" \
    -H "x-api-key: $API_KEY" -H "Authorization: Bearer $TOKEN" \
    -H "cooperativa: $COOPERATIVA" -H "posto: $POSTO" -H "codigoBeneficiario: $COD_BENEF" \
    -H "Accept: application/pdf")
  if [[ "$status" =~ ^2 ]]; then ok "✓ PDF salvo em $out (HTTP $status, $(file -b "$out"))"; else err "✗ HTTP $status"; cat "$out"; rm -f "$out"; fi
}

baixar() {
  local nn="${1:-$NOSSO}"
  [[ -z "$nn" ]] && read -rp "nossoNumero: " nn
  confirmar_producao "baixar/cancelar o boleto $nn" || return 1
  info "→ PATCH /cobranca/boleto/v1/boletos/$nn/baixa"
  api PATCH "/cobranca/boleto/v1/boletos/$nn/baixa" "{}"
}

webhook_criar() {
  confirmar_producao "registrar contrato de webhook" || return 1
  local url
  read -rp "URL do webhook (https): " url
  local payload
  payload=$(jq -n --arg coop "$COOPERATIVA" --arg posto "$POSTO" --arg cb "$COD_BENEF" --arg url "$url" \
    '{cooperativa:$coop, posto:$posto, codBeneficiario:$cb, eventos:["LIQUIDACAO"], url:$url, urlStatus:"ATIVO", contratoStatus:"ATIVO"}')
  info "→ POST /cobranca/boleto/v1/webhook/contrato/"
  api POST "/cobranca/boleto/v1/webhook/contrato/" "$payload"
}

webhook_consultar() {
  info "→ GET /cobranca/boleto/v1/webhook/contratos/"
  api GET "/cobranca/boleto/v1/webhook/contratos/?cooperativa=$COOPERATIVA&posto=$POSTO&beneficiario=$COD_BENEF"
}

fluxo_completo() {
  autenticar || return; pausa
  cadastrar || return
  [[ -z "$NOSSO" ]] && return
  pausa; consultar "$NOSSO"
  pausa; pdf
  pausa; baixar "$NOSSO"
}

# ----------------------------------------------------------------------------
# Menu
# ----------------------------------------------------------------------------
menu() {
  cat <<MENU

${C_INFO}=== Sicredi Boleto — Sandbox ===${C_RST}
  1) Autenticar (token)
  2) Cadastrar boleto
  3) Consultar boleto
  4) Baixar PDF
  5) Baixar/cancelar boleto
  6) Webhook: criar contrato
  7) Webhook: consultar contratos
  9) Fluxo completo (1→2→3→4→5)
  0) Sair
  ambiente: $([[ "$EH_PRODUCAO" == "1" ]] && echo "${C_ERR}PRODUÇÃO${C_RST}" || echo "${C_OK}sandbox${C_RST}")
${C_DIM}  token: $([[ -n "$TOKEN" ]] && echo presente || echo ausente) | nossoNumero: ${NOSSO:-—}${C_RST}
MENU
  read -rp "opção> " opt
  case "$opt" in
    1) autenticar ;;
    2) cadastrar ;;
    3) consultar ;;
    4) pdf ;;
    5) baixar ;;
    6) webhook_criar ;;
    7) webhook_consultar ;;
    9) fluxo_completo ;;
    0) exit 0 ;;
    *) err "opção inválida" ;;
  esac
  pausa   # deixa a saída visível antes de o menu ser redesenhado
}

while true; do menu; done
