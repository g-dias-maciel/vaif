# Beatriz Conversation Test Scripts

Past each script into the Telegram bot. Check Beatriz's responses against the contract.

## 1. Ideal flow — lead knows what they want, accepts price

```
👤 Lead: Oi, queria fazer uma tatuagem
✅ Check: Beatriz se apresenta, pergunta nome
👤 Lead: Me chamo Lucas
✅ Check: Usa o nome. Pergunta se é primeira tattoo.
👤 Lead: Não, já tenho algumas
✅ Check: Não explica processo de dor/cicatrização. Pergunta local.
👤 Lead: Quero fechar o braço direito, estilo realismo
✅ Check: Usa a info — não pergunta local nem estilo de novo. Pede referência.
👤 Lead: [envia foto de referência]
✅ Check: Elogia referência. "Adorei sua referência!" Conecta com especialidade do artista.
👤 Lead: É mais pela estética mesmo, curto o trampo do artista
✅ Check: Anota estética. Entra em valor: "Tenho certeza que você vai amar..."
👤 Lead: Beleza
✅ Check: Explica processo criativo (arte junto com cliente, aprovação, encaixe)
👤 Lead: Entendi, sem dúvidas
✅ Check: SÓ AGORA apresenta preço. À vista + parcelado. "Como fica para você?" e PARA.
👤 Lead: Fechado! Como faço o sinal?
✅ Check: Pede sinal via PIX. Pede data/horário. Explica desconto do sinal no valor total.
```

## 2. Lead haggles — negotiation with contrapartida

```
👤 Lead: Boa noite
✅ Check: Apresentação. Pergunta nome.
👤 Lead: Rafael
✅ Check: Primeira tattoo? Local?
👤 Lead: Primeira sim. Queria no antebraço esquerdo
✅ Check: Explica processo de dor/cicatrização (primeira tattoo). Pergunta estilo.
👤 Lead: Old school, aquelas tradicionais
✅ Check: Pede referência.
👤 Lead: [envia foto]
✅ Check: Elogia. Conecta especialidade. Projeta resultado.
👤 Lead: Show
✅ Check: Explica processo.
👤 Lead: Pode mandar o preço
✅ Check: PRIMEIRO pergunta "Alguma dúvida?". SÓ DEPOIS manda preço. "Como fica para você?" e PARA.
👤 Lead: Pô, tá salgado... não tem desconto não?
✅ Check: Descobre motivo. Pergunta "Qual valor você imaginava investir?"
👤 Lead: Uns R$ 400
✅ Check: Se dentro do piso — contrapartida COM post no Instagram. "Vamos fazer o seguinte..."
✅ Check: Se abaixo do piso — handoff. "Deixa eu te passar direto pro [artista]..."
```

## 3. Below-floor — handoff imediato

```
👤 Lead: Quero fechar as costas, realismo
✅ Check: Qualificação normal. Preço normal.
👤 Lead: Tá muito caro. Faço por R$ 100
✅ Check: Pergunta "Qual valor imaginava?" — se R$100 < piso → handoff IMEDIATO. Não negocia.
```

## 4. Cover-up — handoff imediato

```
👤 Lead: Oi, quero cobrir uma tatuagem antiga
✅ Check: Handoff IMEDIATO ao detectar "cobrir". "Cover-ups são bem específicos — vou te passar direto pro [artista]..." NÃO pergunta local/estilo/preço.
```

## 5. Vague lead — duas tentativas, depois handoff

```
👤 Lead: Quero fazer uma tattoo
✅ Check: Pergunta local.
👤 Lead: Sei lá, algo maneiro
✅ Check: Pergunta estilo OU pede referência.
👤 Lead: Umas coisas da hora
✅ Check: 2ª tentativa vaga → handoff. "Deixa eu te passar pro [artista], ele vai conseguir entender melhor..."
```

## 6. Lead sends voice message

```
👤 Lead: [envia áudio]
✅ Check: "Me manda por texto, por favor? Assim consigo te ajudar melhor."
👤 Lead: [envia áudio de novo]
✅ Check: 2º áudio → handoff. Passa pro artista.
```

## 7. Style mismatch — graceful decline + conversion attempt

```
👤 Lead: Quero uma tattoo estilo aquarela no braço
✅ Check: Se artista não faz aquarela: "O [artista] é especialista em [estilos]. Dá pra trazer um pouco dessa vibe aquarela no estilo [artista]. O que acha?"
```

## 8. Off-hours

```
👤 Lead: Oi (23h da noite)
✅ Check: "O estúdio está fechado agora. Retorno amanhã às 9h. Seu atendimento está salvo..."
```

## 9. Abuse/troll

```
👤 Lead: Seus trabalhos são uma merda
✅ Check: Resposta única: "Infelizmente não posso continuar essa conversa. Se precisar de algo, estamos à disposição." Não responde mais.
```

## 10. Lead asks for artist directly

```
👤 Lead: Quero falar com o artista direto
✅ Check: "[Artista] está no meio de uma sessão de tatuagem agora. Vou tentar falar com ele. Enquanto isso, posso continuar te ajudando ou prefere aguardar retorno dele?"
```
