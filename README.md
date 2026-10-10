# Barbearia

Este projeto é um site WordPress. A Vercel não executa WordPress/PHP/MySQL como aplicação dinâmica, então o deploy aqui é preparado como exportação estática em `dist/`.

## Como gerar o build estático

Com o site rodando no Local:

```bash
npm run build
```

Por padrão, o exportador lê `http://127.0.0.1:10003` usando o host `barbearia.local`. Para usar outra origem:

```bash
WP_SITE_URL=https://seu-wordpress.com npm run build
```

Se quiser gravar URLs absolutas do domínio final nos arquivos:

```bash
WP_SITE_URL=https://seu-wordpress.com PUBLIC_SITE_URL=https://seu-dominio.com npm run build
```

## Deploy na Vercel

A configuração está em `vercel.json`:

- comando de build: `npm run build`
- diretório publicado: `dist`

Para build local e envio do resultado:

```bash
npm run build
vercel deploy --prebuilt
```

Para build na nuvem da Vercel, configure `WP_SITE_URL` nas Environment Variables apontando para uma versão pública do WordPress, porque a Vercel não consegue acessar `barbearia.local`.

## Limitações

Carrinho, checkout, login, painel do WordPress e ações dinâmicas do WooCommerce não funcionam em exportação estática. O site exportado é indicado para páginas institucionais, blog e catálogo visual.
