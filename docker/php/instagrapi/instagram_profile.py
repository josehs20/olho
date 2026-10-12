#!/usr/bin/env python3
"""Coleta pública de perfil do Instagram via instagrapi (prompt 48).

Executado como **processo separado** pelo `InstagramAdapter`
(`App\\Sources\\Adapters\\InstagramAdapter`), nunca dentro do FPM. O resultado é
um **objeto JSON** no stdout e um exit code que só indica se o script concluiu —
o estado de negócio (sessão, perfil) vai dentro do JSON.

Formatos:

- `--username <alvo>`: enriquecimento de perfil (etapa 2 da pipeline). Devolve
  metadados públicos do perfil e a URL do avatar (evidência visual).
- `--mode session`: teste de conexão do painel admin (`cl.user_info_by_username('instagram')`).
  Não consulta alvo algum; só confirma se o cookie está ativo.

Padrão de saída:

    {"ok": true, "session_status": "HEALTHY", "found": true, "profile": {...}}

Sessão revogada/expirada → `session_status: EXPIRED`; verificação humana →
`CHALLENGE_REQUIRED`. O adapter traduz esses dois estados em exceções com status
`skipped`: **nada** foi consultado sobre o alvo, então o crédito é estornado.

Segurança e limites:
- o `sessionid` chega pelo ambiente (`INSTAGRAPI_SESSIONID`) e **nunca** entra na
  linha de comando (argv aparece em `ps`);
- nenhum dado privado é coletado: apenas os campos públicos do perfil;
- erros são reportados por mensagem curta e sanitizada (sem caminhos internos,
  sem cookie, sem URL de rede);
- CAPTCHA/challenge nunca é contornado: o script interrompe e reporta.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
from typing import Any

DEFAULT_CHALLENGE_MARKERS = (
    "challenge",
    "checkpoint",
    "consent_required",
    "two_factor",
    "login_required",
    "bad_password",
)

# Serviço neutro usado apenas para confirmar que a sessão responde (o teste de
# conexão do painel). Não é um alvo: é a própria conta oficial do Instagram.
SESSION_PROBE_USERNAME = "instagram"


def report(payload: dict[str, Any], exit_code: int = 0) -> None:
    """Escreve o JSON de saída e encerra o processo."""
    sys.stdout.write(json.dumps(payload, ensure_ascii=False))
    sys.stdout.flush()
    raise SystemExit(exit_code)


def env_int(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, default))
    except (TypeError, ValueError):
        return default


def redact_secrets(text: str) -> str:
    """Remove credenciais que possam vazar em mensagens de erro de rede.

    Uma falha de proxy (DNS, TLS, connect) costuma citar a URL inteira, incluindo
    `user:pass@host`. Redigimos a credencial para a mensagem nunca carregar o
    segredo — regra do prompt 48 §15 (nunca registrar tokens/credenciais).
    """
    # scheme://user:pass@host -> scheme://***:***@host
    text = re.sub(r"(?i)\b((?:https?|socks[45]h?)://)[^\s/@]+:[^\s/@]+@", r"\1***:***@", text)
    # O cookie da sessão, se por acaso aparecer, também sai.
    session = os.environ.get("INSTAGRAPI_SESSIONID", "").strip()
    if session:
        text = text.replace(session, "***")
    return text


def sanitize(message: Any, limit: int = 300) -> str:
    """Mensagem curta e sem dados sensíveis para o operador."""
    text = str(message or "").replace("\n", " ").strip()
    return redact_secrets(text)[:limit]


def session_status_for(error: Exception) -> str:
    """Classifica a exceção do instagrapi em um estado de sessão do painel."""
    haystack = f"{type(error).__name__}: {error}".lower()

    if "challenge" in haystack or "checkpoint" in haystack:
        return "CHALLENGE_REQUIRED"

    if any(marker in haystack for marker in DEFAULT_CHALLENGE_MARKERS):
        return "EXPIRED"

    return "EXPIRED"


def load_client():
    """Cria o `Client` do instagrapi com o cookie do ambiente.

    O instagrapi 2.x não autentica mais por `login()` com senha; a forma
    suportada e a que evita desafios é reaproveitar a sessão web (`sessionid`) de
    uma conta de serviço. `set_settings` injeta o cookie e o `cl` já sai
    autenticado.
    """
    from instagrapi import Client

    session_id = os.environ.get("INSTAGRAPI_SESSIONID", "").strip()
    ds_user_id = os.environ.get("INSTAGRAPI_DS_USER_ID", "").strip()
    proxy = os.environ.get("INSTAGRAPI_PROXY", "").strip()

    if not session_id:
        return None

    settings: dict[str, Any] = {"sessionid": session_id}

    if ds_user_id:
        settings["ds_user_id"] = ds_user_id

    client = Client()
    client.request_timeout = env_int("INSTAGRAPI_REQUEST_TIMEOUT", 15)
    client.set_settings(settings)

    # Proxy de egresso (HTTP/HTTPS/SOCKS). O Instagram vincula a sessão ao IP de
    # origem e limita IPs de datacenter (429 / login_required); sem um IP
    # residencial/limpo a sessão válida do burner é recusada mesmo funcionando no
    # navegador. A URL pode trazer credenciais — fica só no ambiente do processo.
    if proxy:
        client.set_proxy(proxy)

    return client


def execute_session_probe(client) -> dict[str, Any]:
    """Teste de conexão (§2.1): a sessão responde a uma consulta pública?"""
    profile = client.user_info_by_username(SESSION_PROBE_USERNAME)

    return {
        "ok": True,
        "session_status": "HEALTHY",
        "account_username": getattr(profile, "username", None),
        "user_id": str(getattr(profile, "pk", "") or "") or None,
    }


def execute_profile(client, username: str) -> dict[str, Any]:
    """Enriquecimento do perfil: metadados públicos + avatar (evidência visual)."""
    try:
        profile = client.user_info_by_username(username)
    except Exception as error:  # noqa: BLE001 — "não existe" é desfecho legítimo
        if "not found" in f"{type(error).__name__}: {error}".lower() or "404" in str(error):
            return {
                "ok": True,
                "session_status": "HEALTHY",
                "found": False,
                "warnings": ["Nenhum perfil público corresponde a este username."],
            }

        raise

    return {
        "ok": True,
        "session_status": "HEALTHY",
        "found": True,
        "profile": {
            "username": getattr(profile, "username", None) or username,
            "user_id": str(getattr(profile, "pk", "") or "") or None,
            "full_name": getattr(profile, "full_name", None),
            "biography": getattr(profile, "biography", None),
            "profile_url": f"https://www.instagram.com/{username}/",
            # A URL do avatar é o insumo da galeria/correlação por pHash; o
            # cálculo da similaridade roda fora da coleta (prompt 48 §1).
            "avatar_url": str(getattr(profile, "profile_pic_url_hd", None)
                              or getattr(profile, "profile_pic_url", None) or "") or None,
            "is_private": bool(getattr(profile, "is_private", False)),
            "is_verified": bool(getattr(profile, "is_verified", False)),
            "is_business": bool(getattr(profile, "is_business", False)),
            "followers": getattr(profile, "follower_count", None),
            "following": getattr(profile, "following_count", None),
            "posts": getattr(profile, "media_count", None),
            "external_url": getattr(profile, "external_url", None),
        },
    }


def main() -> None:
    parser = argparse.ArgumentParser(description="Coleta pública de perfil do Instagram (instagrapi)")
    parser.add_argument("--username", help="perfil público a enriquecer")
    parser.add_argument("--mode", choices=["profile", "session"], default="profile")
    args = parser.parse_args()

    username = (args.username or "").strip().lower()

    if args.mode == "profile" and not username:
        report({"ok": False, "error_code": "invalid_input", "error": "Informe o username."}, exit_code=2)

    try:
        import instagrapi  # noqa: F401 — presença do pacote é pré-requisito
    except Exception as error:  # noqa: BLE001
        report({
            "ok": False,
            "error_code": "module_unavailable",
            "error": f"instagrapi indisponível: {sanitize(error)}",
        }, exit_code=3)

    # O instagrapi 2.x não expõe `__version__` (só a versão via metadados do
    # pacote); a versão é diagnóstico e nunca deve derrubar a execução.
    try:
        from importlib.metadata import version as package_version

        instagrapi_version = package_version("instagrapi")
    except Exception:  # noqa: BLE001
        instagrapi_version = "desconhecida"

    try:
        client = load_client()
    except Exception as error:  # noqa: BLE001
        report({
            "ok": False,
            "session_status": session_status_for(error),
            "error_code": "session_invalid",
            "error": sanitize(error),
        })

    if client is None:
        report({
            "ok": False,
            "session_status": "EXPIRED",
            "error_code": "session_missing",
            "error": "Sessão do Instagram não configurada.",
        })

    try:
        payload = execute_session_probe(client) if args.mode == "session" else execute_profile(client, username)
    except Exception as error:  # noqa: BLE001 — a classificação decide o desfecho
        payload = {
            "ok": False,
            "session_status": session_status_for(error),
            "error_code": "session_invalid",
            "error": sanitize(error),
        }

    payload.setdefault("instagrapi_version", instagrapi_version)

    report(payload, exit_code=0 if payload.get("ok") else 1)


if __name__ == "__main__":
    main()

