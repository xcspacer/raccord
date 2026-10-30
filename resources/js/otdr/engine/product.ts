/**
 * Identity written into the generated files (workbook creator, `.txt` line 2, logs, event comments). It comes from the
 * platform settings at runtime: the library carries no product or company name of its own.
 */
export interface ProductInfo {
    /** Full product name, e.g. "{platform} Recette OTDR". */
    name: string;
    version: string;
    /** Short name used in event comments and in the corrections file names; defaults to the product name. */
    brand: string;
}

const DEFAULT_NAME = 'Recette OTDR';

const current: ProductInfo = { name: DEFAULT_NAME, version: '1.0.0', brand: DEFAULT_NAME };

export function configureProduct(info: Partial<ProductInfo>): void {
    if (info.name !== undefined && info.name.trim() !== '') {
        current.name = info.name.trim();
    }

    if (info.version !== undefined && info.version.trim() !== '') {
        current.version = info.version.trim();
    }

    if (info.brand !== undefined && info.brand.trim() !== '') {
        current.brand = info.brand.trim();
    } else if (info.name !== undefined && info.name.trim() !== '') {
        current.brand = current.name;
    }
}

export function product(): Readonly<ProductInfo> {
    return current;
}
