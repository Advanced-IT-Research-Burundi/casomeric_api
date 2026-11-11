<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <title>Votre demande d'assistance a été refusée</title>
</head>
<body style="margin:0;padding:0;background:#f7f7f9;font-family:Arial, Helvetica, sans-serif;color:#222;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f7f7f9;padding:24px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e6e6ee;">
                    <tr>
                        <td style="background:#0f172a;color:#ffffff;padding:18px 24px;font-size:18px;font-weight:bold;">
                            CASOMIREC
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 12px 0;font-size:16px;">
                                Bonjour
                                <strong>
                                    {{ $assistance->membre->full_name ?? ($assistance->membre->prenom . ' ' . $assistance->membre->nom) }}
                                </strong>,
                            </p>

                            <p style="margin:0 0 12px 0;line-height:1.6;">
                                Nous sommes au regret de vous informer que votre demande d'assistance
                                <strong>#{{ $assistance->id }}</strong> a été <strong>refusée</strong>
                                le
                                <strong>{{ \Carbon\Carbon::parse($assistance->updated_at ?? now())->format('d/m/Y') }}</strong>.
                            </p>

                            <div style="margin:18px 0;padding:16px;border:1px solid #e6e6ee;border-radius:6px;background:#fff7f7;">
                                <p style="margin:0 0 8px 0;"><strong>Informations de la demande</strong></p>
                                <ul style="margin:8px 0 0 18px;padding:0;line-height:1.6;">
                                    <li>Type d'assistance :
                                        <strong>{{ $assistance->typeAssistance->nom ?? 'N/A' }}</strong>
                                    </li>
                                    <li>Montant demandé :
                                        <strong>{{ number_format((float) $assistance->montant, 0, ',', ' ') }} FBu</strong>
                                    </li>
                                </ul>
                            </div>

                            @if(!empty($assistance->motif_rejet))
                                <div style="margin:18px 0;padding:16px;border:1px solid #fde2e2;border-radius:6px;background:#fffafa;">
                                    <p style="margin:0 0 8px 0;"><strong>Motif du refus</strong></p>
                                    <p style="margin:0;white-space:pre-line;">
                                        {{ $assistance->motif_rejet }}
                                    </p>
                                </div>
                            @endif

                            <p style="margin:0 0 12px 0;line-height:1.6;">
                                Vous pouvez soumettre une nouvelle demande ultérieurement.
                                Pour toute précision, répondez simplement à cet email.
                            </p>

                            <p style="margin:16px 0 0 0;">
                                Cordialement,<br/>
                                <strong>Équipe CASOMIREC</strong>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="background:#f1f5f9;color:#475569;font-size:12px;padding:12px 24px;">
                            Cet email vous est adressé suite à l’étude de votre demande d'assistance. Merci de ne pas partager ces informations avec des tiers.
                        </td>
                    </tr>
                </table>

                <p style="color:#64748b;font-size:12px;margin:12px 0 0 0;">
                    © {{ date('Y') }} CASOMIREC. Tous droits réservés.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
