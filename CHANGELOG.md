# Changelog

Todas as mudanças relevantes deste plugin ficam registradas aqui.
O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e as versões
seguem o [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Não lançado]

Requer Atualizar em Configurar > Plugins.

### Adicionado
- Entrega de rádios para embarcação: o gestor separa vários rádios e as baterias reserva,
  fotografa, tira a selfie e entrega a uma embarcação cadastrada pelo TI.
- Recebimento de volta da embarcação no mesmo modelo, inclusive em partes, com fotos e selfie.
- No recebimento, as baterias devolvidas ficam limitadas às que foram entregues.
- Rádio com problema na entrega ou no recebimento: problema novo bloqueia o rádio; problema já
  conhecido (laudo em aberto) segue sem bloqueio.
- O que o gestor faz na entrega fica guardado no aparelho e é enviado em partes: a conexão pode
  cair no meio sem perder nada.
- Cadastro de embarcações, lista e detalhe das entregas com CSV, cancelamento da separação pelo TI
  e ação automática que cancela a separação parada além do prazo.

### Alterado
- A desinstalação não apaga mais as fotos do defeito nem as fotos das entregas; só as selfies.
- Rádios entregues a embarcações não passam pela conferência do setor nem pelo alerta de não
  devolvido.

### Corrigido
- O TI não conseguia criar nem excluir problemas do checklist.
- A resposta "Não" da devolução não era gravada; a atualização corrige as devoluções já
  registradas.

## [0.10.0] - 2026-10-03

Requer Atualizar em Configurar > Plugins.

### Adicionado
- Perfis padrão criados na instalação: Operador (interface simplificada) e Gestor Operacional.
  Perfis que já existem não são alterados.
- Fotos do equipamento com defeito: de 1 a 5 fotos, obrigatórias ao marcar um problema.
- Integração opcional com o plugin Laudo: o equipamento com laudo em aberto aparece com o selo
  "Problema conhecido" e o problema já conhecido não bloqueia o item de novo.
- Configuração da integração com o Laudo e das situações consideradas concluídas.

## Versões anteriores

As versões até a 0.9.0 (retirada e devolução com checklist, selfie com localização, conferência do
gestor por turno, bloqueio do item com problema e registro de uso) não foram publicadas como
release.

[Não lançado]: https://github.com/tuliodutra27/GLPI-Plugin-Checklist-Itens/compare/v0.10.0...HEAD
[0.10.0]: https://github.com/tuliodutra27/GLPI-Plugin-Checklist-Itens/releases/tag/v0.10.0
