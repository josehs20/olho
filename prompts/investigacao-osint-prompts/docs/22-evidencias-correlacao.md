# Evidências e Correlação

Uma descoberta deve apontar para sua fonte e para o dado observado.

Exemplo:

``` text
finding
  source = maigret
  platform = github
  username = exemplo
  url = https://...
  observed_at = ...
```

A correlação deve guardar os fatores que contribuíram para uma
associação.

Exemplo:

``` json
{
  "factors": [
    {"type": "same_username", "weight": 40},
    {"type": "matching_public_name", "weight": 20}
  ]
}
```

O valor é um indicador interno de correlação, não uma probabilidade de
identidade.
