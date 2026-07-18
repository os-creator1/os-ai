/**
 * Item Name: Ultimate SMS - Bulk SMS Application For Marketing
 * Author: Codeglen
 * Author URL: https://codecanyon.net/user/codeglen
 */

class SMSCounter {
  // Constants
  static GSM_7BIT = 'GSM_7BIT';
  static GSM_7BIT_EX = 'GSM_7BIT_EX';
  static UTF16 = 'UTF16';
  static WHATSAPP = 'WHATSAPP';

  static GSM_7BIT_LEN = 160;
  static GSM_7BIT_EX_LEN = 160;
  static UTF16_LEN = 70;
  static WHATSAPP_LEN = 1000;

  static GSM_7BIT_LEN_MULTIPART = 153;
  static GSM_7BIT_EX_LEN_MULTIPART = 153;
  static UTF16_LEN_MULTIPART = 67;

  /**
   * @returns {number[]}
   */
  getGsm7bitMap() {
    return [
      10, 12, 13, 32, 33, 34, 35, 36,
      37, 38, 39, 40, 41, 42, 43, 44,
      45, 46, 47, 48, 49, 50, 51, 52,
      53, 54, 55, 56, 57, 58, 59, 60,
      61, 62, 63, 64, 65, 66, 67, 68,
      69, 70, 71, 72, 73, 74, 75, 76,
      77, 78, 79, 80, 81, 82, 83, 84,
      85, 86, 87, 88, 89, 90, 91, 92,
      93, 94, 95, 97, 98, 99, 100, 101,
      102, 103, 104, 105, 106, 107, 108,
      109, 110, 111, 112, 113, 114, 115,
      116, 117, 118, 119, 120, 121, 122,
      123, 124, 125, 126, 161, 163, 164,
      165, 167, 191, 196, 197, 198, 199,
      201, 209, 214, 216, 220, 223, 224,
      228, 229, 230, 232, 233, 236, 241,
      242, 246, 248, 249, 252, 915, 916,
      920, 923, 926, 928, 931, 934, 936,
      937, 8364, // €
    ];
  }

  /**
   * @returns {number[]}
   */
  getAddedGsm7bitExMap() {
    return [12, 91, 92, 93, 94, 123, 124, 125, 126, 8364];
  }

  /**
   * @returns {number[]}
   */
  getGsm7bitExMap() {
    return [
      ...this.getGsm7bitMap(),
      ...this.getAddedGsm7bitExMap(),
    ];
  }

  /**
   * @returns {number[]}
   */
  getTurkishGsm7bitMap() {
    return [
      10, 12, 13, 32, 33, 34, 35, 36,
      37, 38, 39, 40, 41, 42, 43, 44,
      45, 46, 47, 48, 49, 50, 51, 52,
      53, 54, 55, 56, 57, 58, 59, 60,
      61, 62, 63, 64, 65, 66, 67, 68,
      69, 70, 71, 72, 73, 74, 75, 76,
      77, 78, 79, 80, 81, 82, 83, 84,
      85, 86, 87, 88, 89, 90, 91, 92,
      93, 94, 95, 97, 98, 99, 100, 101,
      102, 103, 104, 105, 106, 107, 108,
      109, 110, 111, 112, 113, 114, 115,
      116, 117, 118, 119, 120, 121, 122,
      123, 124, 125, 126, 163, 164, 165,
      167, 196, 197, 199, 201, 209, 214,
      220, 223, 224, 228, 229, 231, 233,
      241, 242, 246, 249, 252, 286, 287,
      304, 305, 350, 351, 915, 916, 920,
      923, 926, 928, 931, 934, 936, 937,
      8364,
    ];
  }

  /**
   * @returns {number[]}
   */
  getAddedTurkishGsm7bitExMap() {
    return [12, 91, 92, 93, 94, 123, 124, 125, 126, 286, 287, 304, 305, 350, 351, 8364];
  }

  /**
   * @returns {number[]}
   */
  getAddedSpanishGsm7bitExMap() {
    return [12, 91, 92, 93, 94, 123, 124, 125, 126, 193, 205, 211, 218, 225, 231, 237, 243, 250, 8364];
  }

  /**
   * @returns {number[]}
   */
  getPortugueseGsm7bitMap() {
    return [
      10, 12, 13, 32, 33, 34, 35, 36,
      37, 38, 39, 40, 41, 42, 43, 44,
      45, 46, 47, 48, 49, 50, 51, 52,
      53, 54, 55, 56, 57, 58, 59, 60,
      61, 62, 63, 64, 65, 66, 67, 68,
      69, 70, 71, 72, 73, 74, 75, 76,
      77, 78, 79, 80, 81, 82, 83, 84,
      85, 86, 87, 88, 89, 90, 91, 92,
      93, 94, 95, 96, 97, 98, 99, 100,
      101, 102, 103, 104, 105, 106, 107, 108,
      109, 110, 111, 112, 113, 114, 115, 116,
      117, 118, 119, 120, 121, 122, 123, 124,
      125, 126, 163, 165, 167, 170, 186, 192,
      193, 194, 195, 199, 201, 202, 205, 211,
      212, 213, 218, 220, 224, 225, 226, 227,
      231, 233, 234, 237, 242, 243, 244, 245,
      250, 252, 915, 916, 920, 928, 931, 934,
      936, 937, 8364, 8734,
    ];
  }

  /**
   * @returns {number[]}
   */
  getAddedPortugueseGsm7bitExMap() {
    return [
      12, 91, 92, 93, 94, 123, 124, 125,
      126, 193, 194, 195, 202, 205, 211, 212,
      213, 218, 225, 226, 227, 231, 234, 237,
      242, 243, 245, 250, 915, 920, 928, 931,
      934, 936, 937, 8364,
    ];
  }

  /**
   * Detects the encoding, Counts the characters, message length, remaining characters.
   *
   * @param {string} text
   * @param {string} [type=SMSCounter.GSM_7BIT]
   * @returns {{encoding: string, length: number, per_message: number, remaining: number, messages: number}}
   */
  count(text, type = SMSCounter.GSM_7BIT) {
    return this.doCount(text, false, type);
  }

  /**
   * Detects the encoding, Counts the characters, message length, remaining characters.
   * Supports language shift tables characters.
   *
   * @param {string} text
   * @returns {{encoding: string, length: number, per_message: number, remaining: number, messages: number}}
   */
  countWithShiftTables(text) {
    return this.doCount(text, true);
  }

  /**
   * Converts a UTF-8 string to an array of Unicode code points.
   * In modern JS, we use Array.from and codePointAt.
   *
   * @param {string} str
   * @returns {number[]}
   */
  utf8ToUnicode(str) {
    return Array.from(str, char => char.codePointAt(0));
  }

  /**
   * Converts unicode code points array to a utf8 str.
   * In modern JS, we use String.fromCodePoint.
   *
   * @param {number[]} array unicode codepoints array
   * @returns {string} utf8 encoded string
   */
  unicodeToUtf8(array) {
    return String.fromCodePoint(...array);
  }

  /**
   * Detects the encoding of a particular text.
   *
   * @param {string|number[]} text
   * @param {number[]} exChars - passed by reference, filled with extended chars
   * @returns {string} (GSM_7BIT|GSM_7BIT_EX|UTF16)
   */
  detectEncoding(text, exChars) {
    const codePoints = Array.isArray(text) ? text : this.utf8ToUnicode(text);
    const gsm7bitExMapSet = new Set(this.getGsm7bitExMap());
    const addedGsm7bitExMapSet = new Set(this.getAddedGsm7bitExMap());

    const utf16Chars = codePoints.filter(cp => !gsm7bitExMapSet.has(cp));
    if (utf16Chars.length > 0) {
      return SMSCounter.UTF16;
    }

    const extendedChars = codePoints.filter(cp => addedGsm7bitExMapSet.has(cp));
    if (extendedChars.length > 0) {
      exChars.push(...extendedChars); // Push to the passed-in array
      return SMSCounter.GSM_7BIT_EX;
    }

    return SMSCounter.GSM_7BIT;
  }

  /**
   * Detects the encoding of a particular text.
   * Supports language shift tables characters.
   *
   * @param {string|number[]} text
   * @param {number[]} exChars - passed by reference, filled with extended chars
   * @returns {string} (GSM_7BIT|GSM_7BIT_EX|UTF16)
   */
  detectEncodingWithShiftTables(text, exChars) {
    const codePoints = Array.isArray(text) ? text : this.utf8ToUnicode(text);

    const gsmCharMap = [
      ...this.getGsm7bitExMap(),
      ...this.getTurkishGsm7bitMap(),
      ...this.getAddedTurkishGsm7bitExMap(),
      ...this.getAddedSpanishGsm7bitExMap(),
      ...this.getPortugueseGsm7bitMap(),
      ...this.getAddedPortugueseGsm7bitExMap(),
    ];
    const gsmCharMapSet = new Set(gsmCharMap);

    const utf16Chars = codePoints.filter(cp => !gsmCharMapSet.has(cp));
    if (utf16Chars.length > 0) {
      return SMSCounter.UTF16;
    }

    const addedGsmCharMap = [
      ...this.getAddedGsm7bitExMap(),
      ...this.getAddedTurkishGsm7bitExMap(),
      ...this.getAddedSpanishGsm7bitExMap(),
      ...this.getAddedPortugueseGsm7bitExMap(),
    ];
    const addedGsmCharMapSet = new Set(addedGsmCharMap);

    const extendedChars = codePoints.filter(cp => addedGsmCharMapSet.has(cp));
    if (extendedChars.length > 0) {
      exChars.push(...extendedChars); // Push to the passed-in array
      return SMSCounter.GSM_7BIT_EX;
    }

    return SMSCounter.GSM_7BIT;
  }

  /**
   * @returns {{encoding: string, length: number, per_message: number, remaining: number, messages: number}}
   */
  doCount(text, supportShiftTables, type = SMSCounter.GSM_7BIT) {
    const unicodeArray = this.utf8ToUnicode(text);
    const exChars = []; // Array to be filled by detectEncoding

    let encoding = supportShiftTables
      ? this.detectEncodingWithShiftTables(unicodeArray, exChars)
      : this.detectEncoding(unicodeArray, exChars);

    if (type === SMSCounter.WHATSAPP) {
      encoding = SMSCounter.WHATSAPP;
    }

    let length = unicodeArray.length;

    if (encoding === SMSCounter.GSM_7BIT_EX) {
      // Each exchar in the GSM 7 Bit encoding takes one more space
      length += exChars.length;
    } else if (encoding === SMSCounter.UTF16) {
      // Unicode chars over U+FFFF (65535) occupy an extra byte (surrogate pairs)
      const lengthExtra = unicodeArray.reduce((carry, char) => {
        if (char >= 65536) {
          carry++;
        }
        return carry;
      }, 0);
      length += lengthExtra;
    }

    let perMessage;
    switch (encoding) {
      case SMSCounter.GSM_7BIT:
        perMessage = SMSCounter.GSM_7BIT_LEN;
        if (length > SMSCounter.GSM_7BIT_LEN) {
          perMessage = SMSCounter.GSM_7BIT_LEN_MULTIPART;
        }
        break;
      case SMSCounter.GSM_7BIT_EX:
        perMessage = SMSCounter.GSM_7BIT_EX_LEN;
        if (length > SMSCounter.GSM_7BIT_EX_LEN) {
          perMessage = SMSCounter.GSM_7BIT_EX_LEN_MULTIPART;
        }
        break;
      case SMSCounter.WHATSAPP:
        perMessage = SMSCounter.WHATSAPP_LEN;
        break;
      default: // UTF16
        perMessage = SMSCounter.UTF16_LEN;
        if (length > SMSCounter.UTF16_LEN) {
          perMessage = SMSCounter.UTF16_LEN_MULTIPART;
        }
        break;
    }

    let messages = Math.ceil(length / perMessage);
    let remaining;

    if (encoding === SMSCounter.UTF16 && length > perMessage) {
      let currentMessageLength = 0;
      let messageCount = 1;
      for (const char of unicodeArray) {
        let charLength = char >= 65536 ? 2 : 1;

        if (currentMessageLength + charLength > perMessage) {
          // Start of a new message
          messageCount++;
          currentMessageLength = charLength; // New message starts with this char
        } else {
          currentMessageLength += charLength;
        }
      }
      messages = messageCount;
      // The remaining is calculated based on the last message's length
      remaining = perMessage - currentMessageLength;
    } else {
      remaining = (perMessage * messages) - length;
    }

    // Handle case where text is empty
    if (length === 0) {
      messages = 0;
      remaining = perMessage;
    }

    return {
      encoding: encoding,
      length: length,
      per_message: perMessage,
      remaining: remaining,
      messages: messages,
    };
  }

  // --- Utility Methods ---

  /**
   * Removes non GSM characters from a string (by replacing with empty string).
   * @param {string} str
   * @returns {string}
   */
  removeNonGsmChars(str) {
    return this.replaceNonGsmChars(str);
  }

  /**
   * Replaces non GSM characters from a string.
   * @param {string} str String to be replaced
   * @param {string|null} [replacement=null] String of characters to be replaced with
   * @returns {string|false} (string|false) if replacement string is more than 1 character in length
   */
  replaceNonGsmChars(str, replacement = null) {
    const validCharsSet = new Set(this.getGsm7bitExMap());
    let allChars = this.utf8ToUnicode(str);

    if (replacement !== null && replacement.length > 1) {
      return false;
    }

    let replacementUnicode = null;
    if (replacement !== null && replacement.length === 1) {
      replacementUnicode = this.utf8ToUnicode(replacement)[0];
    }

    let newChars = [];
    for (const charCode of allChars) {
      if (validCharsSet.has(charCode)) {
        newChars.push(charCode);
      } else if (replacement !== null) {
        // PHP logic: if replacement is set, replace; otherwise, remove
        newChars.push(replacementUnicode);
      }
    }

    // If replacement is null, the loop above already filtered them out.
    return this.unicodeToUtf8(newChars);
  }


  /**
   * Removes accents and then removes non-GSM characters.
   * @param {string} str
   * @returns {string|false}
   */
  sanitizeToGSM(str) {
    str = this.removeAccents(str);
    return this.removeNonGsmChars(str);
  }

  /**
   * Replaces accented characters with their non-accented equivalents.
   * Note: This is a direct port of the PHP strtr map, not a general normalization.
   * @param {string} str Message text
   * @returns {string} Sanitized message text
   */
  removeAccents(str) {
    // Direct port of PHP strtr logic using a Map for replacement
    const charMap = new Map([
      // Decompositions for Latin-1 Supplement
      ['ª', 'a'], ['º', 'o'],
      ['À', 'A'], ['Á', 'A'],
      ['Â', 'A'], ['Ã', 'A'],
      ['È', 'E'],
      ['Ê', 'E'], ['Ë', 'E'],
      ['Ì', 'I'], ['Í', 'I'],
      ['Î', 'I'], ['Ï', 'I'],
      ['Ð', 'D'],
      ['Ò', 'O'], ['Ó', 'O'],
      ['Ô', 'O'], ['Õ', 'O'],
      ['Ù', 'U'],
      ['Ú', 'U'], ['Û', 'U'],
      ['Ý', 'Y'],
      ['Þ', 'TH'],
      ['á', 'a'],
      ['â', 'a'], ['ã', 'a'],
      ['ç', 'c'],
      ['ê', 'e'], ['ë', 'e'],
      ['í', 'i'],
      ['î', 'i'], ['ï', 'i'],
      ['ð', 'd'],
      ['ó', 'o'],
      ['ô', 'o'], ['õ', 'o'],
      ['ú', 'u'],
      ['û', 'u'],
      ['ý', 'y'], ['þ', 'th'],
      ['ÿ', 'y'],
      // Decompositions for Latin Extended-A
      ['Ā', 'A'], ['ā', 'a'],
      ['Ă', 'A'], ['ă', 'a'],
      ['Ą', 'A'], ['ą', 'a'],
      ['Ć', 'C'], ['ć', 'c'],
      ['Ĉ', 'C'], ['ĉ', 'c'],
      ['Ċ', 'C'], ['ċ', 'c'],
      ['Č', 'C'], ['č', 'c'],
      ['Ď', 'D'], ['ď', 'd'],
      ['Đ', 'D'], ['đ', 'd'],
      ['Ē', 'E'], ['ē', 'e'],
      ['Ĕ', 'E'], ['ĕ', 'e'],
      ['Ė', 'E'], ['ė', 'e'],
      ['Ę', 'E'], ['ę', 'e'],
      ['Ě', 'E'], ['ě', 'e'],
      ['Ĝ', 'G'], ['ĝ', 'g'],
      ['Ğ', 'G'], ['ğ', 'g'],
      ['Ġ', 'G'], ['ġ', 'g'],
      ['Ģ', 'G'], ['ģ', 'g'],
      ['Ĥ', 'H'], ['ĥ', 'h'],
      ['Ħ', 'H'], ['ħ', 'h'],
      ['Ĩ', 'I'], ['ĩ', 'i'],
      ['Ī', 'I'], ['ī', 'i'],
      ['Ĭ', 'I'], ['ĭ', 'i'],
      ['Į', 'I'], ['į', 'i'],
      ['İ', 'I'], ['ı', 'i'],
      ['Ĳ', 'IJ'], ['ĳ', 'ij'],
      ['Ĵ', 'J'], ['ĵ', 'j'],
      ['Ķ', 'K'], ['ķ', 'k'],
      ['ĸ', 'k'], ['Ĺ', 'L'],
      ['ĺ', 'l'], ['Ļ', 'L'],
      ['ļ', 'l'], ['Ľ', 'L'],
      ['ľ', 'l'], ['Ŀ', 'L'],
      ['ŀ', 'l'], ['Ł', 'L'],
      ['ł', 'l'], ['Ń', 'N'],
      ['ń', 'n'], ['Ņ', 'N'],
      ['ņ', 'n'], ['Ň', 'N'],
      ['ň', 'n'], ['ŉ', 'n'],
      ['Ŋ', 'N'], ['ŋ', 'n'],
      ['Ō', 'O'], ['ō', 'o'],
      ['Ŏ', 'O'], ['ŏ', 'o'],
      ['Ő', 'O'], ['ő', 'o'],
      ['Œ', 'OE'], ['œ', 'oe'],
      ['Ŕ', 'R'], ['ŕ', 'r'],
      ['Ŗ', 'R'], ['ŗ', 'r'],
      ['Ř', 'R'], ['ř', 'r'],
      ['Ś', 'S'], ['ś', 's'],
      ['Ŝ', 'S'], ['ŝ', 's'],
      ['Ş', 'S'], ['ş', 's'],
      ['Š', 'S'], ['š', 's'],
      ['Ţ', 'T'], ['ţ', 't'],
      ['Ť', 'T'], ['ť', 't'],
      ['Ŧ', 'T'], ['ŧ', 't'],
      ['Ũ', 'U'], ['ũ', 'u'],
      ['Ū', 'U'], ['ū', 'u'],
      ['Ŭ', 'U'], ['ŭ', 'u'],
      ['Ů', 'U'], ['ů', 'u'],
      ['Ű', 'U'], ['ű', 'u'],
      ['Ų', 'U'], ['ų', 'u'],
      ['Ŵ', 'W'], ['ŵ', 'w'],
      ['Ŷ', 'Y'], ['ŷ', 'y'],
      ['Ÿ', 'Y'], ['Ź', 'Z'],
      ['ź', 'z'], ['Ż', 'Z'],
      ['ż', 'z'], ['Ž', 'Z'],
      ['ž', 'z'], ['ſ', 's'],
      // Decompositions for Latin Extended-B
      ['Ș', 'S'], ['ș', 's'],
      ['Ț', 'T'], ['ț', 't'],
      // Vowels with diacritic (Vietnamese)
      // unmarked
      ['Ơ', 'O'], ['ơ', 'o'],
      ['Ư', 'U'], ['ư', 'u'],
      // grave accent
      ['Ầ', 'A'], ['ầ', 'a'],
      ['Ằ', 'A'], ['ằ', 'a'],
      ['Ề', 'E'], ['ề', 'e'],
      ['Ồ', 'O'], ['ồ', 'o'],
      ['Ờ', 'O'], ['ờ', 'o'],
      ['Ừ', 'U'], ['ừ', 'u'],
      ['Ỳ', 'Y'], ['ỳ', 'y'],
      // hook
      ['Ả', 'A'], ['ả', 'a'],
      ['Ẩ', 'A'], ['ẩ', 'a'],
      ['Ẳ', 'A'], ['ẳ', 'a'],
      ['Ẻ', 'E'], ['ẻ', 'e'],
      ['Ể', 'E'], ['ể', 'e'],
      ['Ỉ', 'I'], ['ỉ', 'i'],
      ['Ỏ', 'O'], ['ỏ', 'o'],
      ['Ổ', 'O'], ['ổ', 'o'],
      ['Ở', 'O'], ['ở', 'o'],
      ['Ủ', 'U'], ['ủ', 'u'],
      ['Ử', 'U'], ['ử', 'u'],
      ['Ỷ', 'Y'], ['ỷ', 'y'],
      // tilde
      ['Ẫ', 'A'], ['ẫ', 'a'],
      ['Ẵ', 'A'], ['ẵ', 'a'],
      ['Ẽ', 'E'], ['ẽ', 'e'],
      ['Ễ', 'E'], ['ễ', 'e'],
      ['Ỗ', 'O'], ['ỗ', 'o'],
      ['Ỡ', 'O'], ['ỡ', 'o'],
      ['Ữ', 'U'], ['ữ', 'u'],
      ['Ỹ', 'Y'], ['ỹ', 'y'],
      // acute accent
      ['Ấ', 'A'], ['ấ', 'a'],
      ['Ắ', 'A'], ['ắ', 'a'],
      ['Ế', 'E'], ['ế', 'e'],
      ['Ố', 'O'], ['ố', 'o'],
      ['Ớ', 'O'], ['ớ', 'o'],
      ['Ứ', 'U'], ['ứ', 'u'],
      // dot below
      ['Ạ', 'A'], ['ạ', 'a'],
      ['Ậ', 'A'], ['ậ', 'a'],
      ['Ặ', 'A'], ['ặ', 'a'],
      ['Ẹ', 'E'], ['ẹ', 'e'],
      ['Ệ', 'E'], ['ệ', 'e'],
      ['Ị', 'I'], ['ị', 'i'],
      ['Ọ', 'O'], ['ọ', 'o'],
      ['Ộ', 'O'], ['ộ', 'o'],
      ['Ợ', 'O'], ['ợ', 'o'],
      ['Ụ', 'U'], ['ụ', 'u'],
      ['Ự', 'U'], ['ự', 'u'],
      ['Ỵ', 'Y'], ['ỵ', 'y'],
      // Vowels with diacritic (Chinese, Hanyu Pinyin)
      ['ɑ', 'a'],
      // macron
      ['Ǖ', 'U'], ['ǖ', 'u'],
      // acute accent
      ['Ǘ', 'U'], ['ǘ', 'u'],
      // caron
      ['Ǎ', 'A'], ['ǎ', 'a'],
      ['Ǐ', 'I'], ['ǐ', 'i'],
      ['Ǒ', 'O'], ['ǒ', 'o'],
      ['Ǔ', 'U'], ['ǔ', 'u'],
      ['Ǚ', 'U'], ['ǚ', 'u'],
      // grave accent
      ['Ǜ', 'U'], ['ǜ', 'u'],
      // spaces
      [' ', ' '], [' ', ' '],
    ]);

    let result = '';
    for (const char of str) {
      result += charMap.get(char) || char;
    }

    return result;
  }

  /**
   * Truncated to the limit of chars allowed by number of SMS.
   * @param {string} str Message text
   * @param {number} limitSms Number of SMS allowed
   * @returns {string} Truncated message
   */
  truncate(str, limitSms) {
    return this.doTruncate(str, limitSms, false);
  }

  /**
   * Truncated to the limit of chars allowed by number of SMS.
   * Supports language shift tables characters.
   * @param {string} str Message text
   * @param {number} limitSms Number of SMS allowed
   * @returns {string} Truncated message
   */
  truncateWithShiftTables(str, limitSms) {
    return this.doTruncate(str, limitSms, true);
  }

  /**
   * @returns {string} Truncated message
   */
  doTruncate(str, limitSms, supportShiftTables) {
    let count = supportShiftTables
      ? this.countWithShiftTables(str)
      : this.count(str);

    if (count.messages <= limitSms) {
      return str;
    }

    let limit;
    if (count.encoding === 'UTF16') {
      limit = SMSCounter.UTF16_LEN;
      if (limitSms > 1) { // PHP checks > 2, but for 1 part it's 70, for > 1 it's 67.
        limit = SMSCounter.UTF16_LEN_MULTIPART;
      }
    } else { // GSM_7BIT or GSM_7BIT_EX
      limit = SMSCounter.GSM_7BIT_LEN;
      if (limitSms > 1) { // PHP checks > 2, but for 1 part it's 160, for > 1 it's 153.
        limit = SMSCounter.GSM_7BIT_LEN_MULTIPART;
      }
    }

    // The PHP logic implements a destructive character-by-character search for the limit
    // by repeatedly truncating and re-counting. We must port that.
    const maxLen = limit * limitSms;
    let unicodeArray = this.utf8ToUnicode(str);

    // Initial slice based on theoretical maximum characters (not counting extended chars yet)
    let sliceArray = unicodeArray.slice(0, maxLen);
    let currentStr = this.unicodeToUtf8(sliceArray);

    // Loop until the message count is correct or we run out of characters
    while (true) {
      count = supportShiftTables
        ? this.countWithShiftTables(currentStr)
        : this.count(currentStr);

      if (count.messages <= limitSms) {
        return currentStr;
      }

      // Reduce length by one character and try again
      if (currentStr.length > 0) {
        // To safely remove the last character, we convert back to code points, pop, and convert back to string
        sliceArray.pop();
        currentStr = this.unicodeToUtf8(sliceArray);
      } else {
        return ""; // Should not happen if original message was > limit, but for safety
      }

      // Note: The PHP loop uses $limit = $limit - 1; which is functionally incorrect
      // as it breaks the multi-part limit. The actual intent is to shrink the string
      // until the correct message count is achieved, which the character-by-character
      // reduction handles better. We keep the logic as an actual character reduction.
    }
  }
}

let SmsCounter = new SMSCounter();
