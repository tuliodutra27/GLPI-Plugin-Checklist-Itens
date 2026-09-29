# Checklist uso de equipamentos — plugin GLPI

Plugin para o [GLPI](https://glpi-project.org/) 10.0.x que controla a **retirada e a devolução de
equipamentos compartilhados do setor** (rádios comunicadores, celulares e tablets).

## Como funciona

**Colaborador** (celular/tablet, interface simplificada ou padrão)
- **Retirada:** tipo → equipamento do seu setor → "Equipamento ok?" → selfie → salvar (logoff).
  - Só aparecem itens do setor (grupo do GLPI, com subgrupos), em estado disponível, que não
    estão com ninguém e não estão bloqueados. Duas pessoas não conseguem pegar o mesmo item.
  - "Não": marca os problemas de uma lista (ninguém digita nada). O item é **bloqueado** e o
    colaborador escolhe outro.
- **Devolução:** só depois da conferência do gestor. "Equipamento ok?" → selfie → salvar →
  "Pegar outro equipamento?". Com problema, o item volta bloqueado.
- Logoff automático por inatividade.

**Gestor do setor**
- **Conferência por turno** (padrão: turnos de 12 h, às 07h e 19h): vê retiradas, devoluções e
  itens bloqueados, com as selfies; tira a própria selfie e confirma. A confirmação libera a
  devolução e abre automaticamente o chamado do TI para cada item bloqueado.
- Alertas de turno sem conferência, pendências de turno anterior e equipamentos não devolvidos.

**TI (administração)**
- Item bloqueado volta a circular sozinho quando o **chamado é solucionado ou fechado**; o TI
  também pode liberar manualmente.
- **Registro de uso de cada equipamento**: situação atual e linha do tempo completa (quem
  retirou, conferiu e devolveu, selfies, problemas, bloqueios e chamados), com exportação CSV.
  Nos telefones, também numa aba do formulário.
- **Alerta de equipamento não devolvido** no fim do turno para os gestores do setor e o TI.
- **Selfies** guardadas fora da pasta pública, pelo menos 90 dias; depois disso, os
  administradores podem limpar (nada é apagado automaticamente).
- Histórico nativo do GLPI em todos os registros; registros de uso não são editáveis.

O ativo (rádio/telefone) **não muda de usuário**: tudo fica nos registros do plugin.

## Requisitos

- GLPI 10.0.x
- PHP 8.0+
- Plugin [Radios](https://github.com/tuliodutra27/GLPI-Plugin-Radios) (opcional — habilita o tipo
  Rádio; o tipo Telefone usa o ativo nativo do GLPI)
- Ações automáticas do GLPI rodando por cron (para o alerta de não devolvido)

## Instalação

1. Copie o conteúdo deste repositório para a pasta `plugins/checklistitens` do GLPI
   (o nome da pasta precisa ser `checklistitens`).
2. Em **Configurar > Plugins**, instale e ative **Checklist uso de equipamentos**.
3. **Saia e entre de novo no GLPI**: os direitos novos só valem para a sessão depois do login.
4. Em **Administração > Perfis**, aba **Checklist uso de equipamentos**, confira os direitos:
   - *Retirada e devolução*: concedido a todos os perfis (inclusive os criados depois);
   - *Conferência do setor (gestor)*: concedido ao perfil de nome "gestor operacional", se existir;
   - *Administração (TI)*: concedido ao Super-Admin.
5. Em **Ativos > Checklist uso de equipamentos > Configuração**, confira os estados disponíveis
   para retirada, limites por pessoa, turnos e categorias de chamado.
6. Coloque colaboradores e gestores nos **grupos** dos seus setores e confira se os equipamentos
   têm grupo preenchido.

## Estrutura

```
front/        telas (colaborador, gestor, equipamentos, TI) e controladores
src/          classes (GlpiPlugin\Checklistitens\...)
templates/    telas em Twig
css/, js/     estilo e comportamento das telas (sem dependências)
setup.php     registro do plugin e hooks
hook.php      instalação, desinstalação e hooks
```

## Licença

GPL-3.0-or-later (ver [`LICENSE`](LICENSE)).
