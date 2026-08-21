# Changelog

## 1.1.4-beta - 2026-08-19

- Mantém acesso completo do arquétipo **Gerente** a todas as funções próprias do plugin.
- Organiza as notas e comentários do PDF em ordem decrescente de nota.
- Mantém respostas sem nota válida ao final da tabela.
- Destaca notas 9–10 em verde, 7–8 em amarelo e 0–6 em vermelho.
- Adiciona uma legenda visual de classificação no PDF.
- Substitui o logotipo de teste pela identidade visual **COLAB-ON** fornecida.
- Aumenta discretamente a área do logotipo no cabeçalho do PDF.
- Não altera cálculo NPS, dados armazenados, dashboard web ou compatibilidade com Moodle 4.4 e 4.5.

## 1.1.3-beta - 2026-08-19

- Remove a tentativa de inserir o acesso no menu **Mais**, que não é exibida de forma consistente por alguns temas no Moodle 4.4.
- Adiciona **Abrir Dashboard NPS** como ação nativa no cabeçalho da atividade Pesquisa.
- O botão fica disponível em Pesquisa, Configurações, Modelos, Análise e Respostas, somente para usuários autorizados.
- Remove o JavaScript usado para reposicionar o botão na página Análise.
- Não altera cálculo NPS, dashboard geral, PDF, permissões ou dados.
- Mantém compatibilidade com Moodle 4.4 e 4.5.


## 1.1.2-beta - 2026-08-19

- Corrige o acesso contextual no Moodle 4.4.
- O item **Abrir Dashboard NPS** agora é adicionado diretamente à navegação de configurações da atividade Pesquisa, permitindo que o Moodle o posicione no menu **Mais**.
- Mantém compatibilidade com Moodle 4.4 e 4.5.


## 1.1.1-beta — 2026-08-19

- Adiciona compatibilidade declarada com Moodle 4.4 e Moodle 4.5.
- Reduz o requisito mínimo para Moodle 4.4.0 (build 2024042200).
- Mantém o acesso pelo menu Mais, dashboard geral e geração de PDF nas duas versões.

## 1.1.0-beta — 2026-08-19

- Corrige o componente das capabilities para `local/feedbackdashboard`.
- Corrige os nomes dos arquivos de idioma.
- Restringe o acesso padrão a gerentes e administradores.
- Adiciona acesso contextual no menu **Mais** da atividade Pesquisa.
- Adiciona dashboard geral em **Administração do site → Relatórios**.
- Adiciona provider de privacidade sem armazenamento próprio.
- Exige escala NPS completa de 0 a 10.
- Corrige a estrutura do pacote de instalação.
