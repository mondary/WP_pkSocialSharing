// apple-translator — bridge entre PHP (plugin WP PK SocialSharing) et Apple FoundationModels.
//
// Lit un JSON sur stdin : {"text":"...","source":"fr","target":"en"}
// Renvoie un JSON sur stdout : {"text":"...","model":"...","ms":123}
// En cas d'erreur, renvoie {"error":"..."} sur stderr avec code sortie non nul.
//
// Compilation : voir build.sh. Cible macOS 26+ (FoundationModels disponible).
// Le binaire n'a aucune dépendance externe : pas de serveur, pas d'Ollama.

import Foundation
#if canImport(FoundationModels)
import FoundationModels
#endif

struct TranslatorInput: Decodable {
    let text: String
    let source: String?
    let target: String
}

struct TranslatorOutput: Encodable {
    let text: String
    let model: String
    let ms: Int
}

struct TranslatorErrorOut: Encodable {
    let error: String
}

@main
struct AppleTranslator {
    static func main() async {
        // Lecture stdin
        let stdin = FileHandle.standardInput
        let data = stdin.availableData
        guard !data.isEmpty else {
            fail("stdin vide")
            return
        }

        let input: TranslatorInput
        do {
            input = try JSONDecoder().decode(TranslatorInput.self, from: data)
        } catch {
            fail("JSON invalide : \(error.localizedDescription)")
            return
        }

        let trimmed = input.text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else {
            fail("text vide")
            return
        }
        let targetName = humanLang(for: input.target)
        let sourceName = input.source.flatMap { $0.isEmpty ? nil : humanLang(for: $0) }

        let started = Date()

        #if canImport(FoundationModels)
        let session = LanguageModelSession()
        var prompt = "Tu es un traducteur professionnel. Traduis le texte suivant en \(targetName)."
        if let src = sourceName {
            prompt += " Langue source : \(src)."
        }
        prompt += " Réponds UNIQUEMENT avec la traduction, sans guillemets, sans commentaire, sans préfixe.\n\nTexte :\n\(trimmed)"

        let response: String
        do {
            let r = try await session.respond(to: prompt)
            response = r.content
        } catch {
            fail("LanguageModelSession.respond : \(error.localizedDescription)")
            return
        }

        var cleaned = response.trimmingCharacters(in: .whitespacesAndNewlines)
        // Certains modèles entourent la sortie de guillemets.
        if (cleaned.hasPrefix("\"") && cleaned.hasSuffix("\""))
            || (cleaned.hasPrefix("'") && cleaned.hasSuffix("'"))
            || (cleaned.hasPrefix("`") && cleaned.hasSuffix("`")) {
            cleaned = String(cleaned.dropFirst().dropLast()).trimmingCharacters(in: .whitespacesAndNewlines)
        }
        if cleaned.isEmpty {
            fail("réponse vide")
            return
        }

        let ms = Int(Date().timeIntervalSince(started) * 1000)
        let out = TranslatorOutput(text: cleaned, model: "apple-foundation-model", ms: ms)
        guard let outData = try? JSONEncoder().encode(out) else {
            fail("encodage sortie impossible")
            return
        }
        FileHandle.standardOutput.write(outData)
        exit(0)
        #else
        fail("FoundationModels framework indisponible (macOS 26+ requis). Compile sur macOS 26+.")
        return
        #endif
    }

    static func humanLang(for code: String) -> String {
        switch code.lowercased() {
        case "en": return "anglais (English)"
        case "fr": return "français"
        case "es": return "espagnol"
        case "de": return "allemand"
        case "it": return "italien"
        case "pt": return "portugais"
        case "nl": return "néerlandais"
        case "pl": return "polonais"
        case "ru": return "russe"
        case "ja": return "japonais"
        case "zh": return "chinois"
        case "ar": return "arabe"
        case "hi": return "hindi"
        default: return "langue de code ISO \(code)"
        }
    }

    static func fail(_ message: String) -> Never {
        let err = TranslatorErrorOut(error: message)
        if let data = try? JSONEncoder().encode(err) {
            FileHandle.standardError.write(data)
        } else {
            FileHandle.standardError.write(Data(message.utf8))
        }
        exit(1)
    }
}
