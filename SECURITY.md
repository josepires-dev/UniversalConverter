# Política de segurança

O UniversalConverter foi desenhado para execução local através de Apache e PHP. **Não exponha esta aplicação diretamente à Internet sem uma revisão de segurança e uma camada de autenticação adequada.**

## Comunicação de vulnerabilidades

Não publique detalhes de vulnerabilidades como issues públicas. Envie uma descrição privada ao mantenedor através do perfil do projeto no GitHub, incluindo a versão afetada, passos para reproduzir e o impacto observado. Não inclua arquivos de entrada que contenham dados pessoais.

## Boas práticas

Mantenha PHP, Apache, ImageMagick, FFmpeg, Poppler e as restantes dependências atualizados. Nunca versiona credenciais, tokens, arquivos enviados por utilizadores ou resultados de conversão. O diretório `storage/` é temporário e deve permanecer protegido contra acesso direto.
