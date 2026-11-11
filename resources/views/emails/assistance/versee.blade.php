<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <title>Votre assistance a été versée</title>
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
                                <strong>{{ trim(($assistance->membre->prenom ?? '') . ' ' . ($assistance->membre->nom ?? '')) }}</strong>,
                            </p>

                            <p style="margin:0 0 12px 0;line-height:1.6;">
                                Nous vous informons que votre assistance
                                <strong>n° {{ $assistance->id }}</strong> a été <strong>versée</strong>
                                le <strong>{{ optional($assistance->date_versement)->format('d/m/Y') ?? now()->format('d/m/Y') }}</strong>.
                            </p>

                            <div style="margin:18px 0;padding:16px;border:1px solid #e6e6ee;border-radius:6px;background:#f0fdf4;">
                                <p style="margin:0 0 8px 0;"><strong>Détails du versement</strong></p>
                                <ul style="margin:8px 0 0 18px;padding:0;line-height:1.6;">
                                    <li>Type d'assistance :
                                        <strong>{{ $assistance->typeAssistance->nom ?? 'N/A' }}</strong>
                                    </li>
                                    <li>Montant versé :
                                        <strong>{{ number_format((float) $assistance->montant, 0, ',', ' ') }} FBU</strong>
                                    </li>
                                    <li>Date de la demande :
                                        <strong>{{ optional($assistance->date_demande)->format('d/m/Y') }}</strong>
                                    </li>
                                    @if ($assistance->date_approbation)
                                        <li>Date d'approbation :
                                            <strong>{{ $assistance->date_approbation->format('d/m/Y') }}</strong>
                                        </li>
                                    @endif
                                </ul>
                            </div>

                            @if ($assistance->preuve_versement)
                                <p style="margin:0 0 12px 0;line-height:1.6;">
                                    La preuve du versement (chèque / bordereau) est disponible ici :
                                    <a href="{{ $assistance->preuve_versement }}" target="_blank" rel="noopener" style="color:#2563eb;">voir la preuve de versement</a>.
                                </p>
                            @endif

                            <p style="margin:0 0 12px 0;line-height:1.6;">
                                Si vous ne retrouvez pas ce montant, contactez l’administration de la caisse
                                en indiquant le numéro de l’assistance.
                            </p>

                            <p style="margin:16px 0 0 0;">
                                Cordialement,<br/>
                                <strong>Équipe CASOMIREC</strong>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="background:#f1f5f9;color:#475569;font-size:12px;padding:12px 24px;">
                            Cet email vous est adressé suite à votre demande d'assistance. Merci de ne pas partager ces informations avec des tiers.
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
