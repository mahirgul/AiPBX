#!/usr/bin/env bash
# ============================================================================
# STEP 0: WELCOME BANNER
# ============================================================================
[[ -t 1 ]] && { clear 2>/dev/null || true; }
AIPBX_VER="$(cat "$SCRIPT_DIR/VERSION" 2>/dev/null || echo dev)"
if [[ "$SEP" == "·" ]]; then
    # "ANSI Shadow" letters; a terminal without UTF-8 gets the plain ones below.
    LOGO=(
' █████╗ ██╗██████╗ ██████╗ ██╗  ██╗'
'██╔══██╗██║██╔══██╗██╔══██╗╚██╗██╔╝'
'███████║██║██████╔╝██████╔╝ ╚███╔╝ '
'██╔══██║██║██╔═══╝ ██╔══██╗ ██╔██╗ '
'██║  ██║██║██║     ██████╔╝██╔╝ ██╗'
'╚═╝  ╚═╝╚═╝╚═╝     ╚═════╝ ╚═╝  ╚═╝'
    )
else
    LOGO=(
'     _    _ ____  ______  __'
'    / \  (_)  _ \| __ ) \/ /'
'   / _ \ | | |_) |  _ \\  / '
'  / ___ \| |  __/| |_) /  \ '
' /_/   \_\_|_|   |____/_/\_\'
''
    )
fi
LOGO_SIDE=('' '' "   ${BOLD}Open-source IP PBX with AI${NC}" "   v${AIPBX_VER} ${SEP} Asterisk 22" "   ${DIM}aipbx.bid${NC}" '')
echo ""
for i in "${!LOGO[@]}"; do
    printf '%b%s%b%b\n' "${GRAD[$i]}${BOLD}" "${LOGO[$i]}" "$NC" "${LOGO_SIDE[$i]}"
done
echo ""
OS_NAME="$(. /etc/os-release 2>/dev/null; echo "${PRETTY_NAME:-Linux}")"
box "$(is_upgrade && echo Upgrade || echo 'Fresh installation')" \
    "Server   $(hostname -f 2>/dev/null || hostname) ${SEP} ${OS_NAME} ${SEP} $(uname -m)" \
    "Source   $SCRIPT_DIR" \
    "Install  $INSTALL_DIR"
echo ""
echo -e "  ${YELLOW}${BOLD}NOTICE & DISCLAIMER (MIT License):${NC}"
echo -e "  AiPBX is open-source software provided ${BOLD}\"AS IS\"${NC} without warranty of any kind."
echo -e "  • ${BOLD}Emergency Calls (112/911):${NC} Not guaranteed; maintain an independent emergency line."
echo -e "  • ${BOLD}SIP Security & Billing:${NC} You are solely responsible for securing trunks and toll costs."
echo -e "  • ${BOLD}Privacy (GDPR/KVKK):${NC} Call recording and AI features require legal consent compliance."
echo -e "  Full license & terms: ${CYAN}$INSTALL_DIR/LICENSE${NC}"
echo ""
